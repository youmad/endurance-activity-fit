<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\Activity\Detail\ActivityInterval;
use Youmad\Endurance\Activity\Detail\Pool\PoolLength;
use Youmad\Endurance\Activity\Detail\Pool\PoolLengthType;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportWarningDetector;
use Youmad\Endurance\ActivityFit\Tests\Fixture\TestFitProfile;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Profile\FieldProfile;
use Youmad\Endurance\Fit\Profile\Generated\GeneratedFitTypeRegistry;
use Youmad\Endurance\Fit\Profile\InMemoryFitProfileRegistry;
use Youmad\Endurance\Fit\Profile\MessageProfile;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\FitBaseType;
use Youmad\Endurance\Fit\Raw\MessageDefinition;
use Youmad\Endurance\Fit\Raw\RawDataMessage;
use Youmad\Endurance\Fit\Raw\RawFieldValue;
use Youmad\Endurance\Fit\Raw\RawStandardField;
use Youmad\Endurance\Fit\Raw\StandardFieldDefinition;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class FitActivityImportWarningDetectorTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testAcceptsInterpretableOptionalActivityEventWithoutProfileConstraint(): void
    {
        self::assertNull(
            (new FitActivityImportWarningDetector())
                ->activityEvent($this->message(event: 1, eventType: 1)),
        );
    }

    public function testAmbiguousOptionalFieldsProduceWarningInsteadOfFailure(): void
    {
        $warning = (new FitActivityImportWarningDetector())
            ->activityEvent($this->ambiguousMessage());

        self::assertNotNull($warning);
        self::assertSame(
            ActivityImportWarningCode::UnknownActivityEvent,
            $warning->code,
        );
        self::assertSame(
            'ambiguous_optional_fields',
            $warning->context['reason'],
        );
    }

    public function testAcceptsKnownOptionalActivityEvent(): void
    {
        self::assertNull(
            (new FitActivityImportWarningDetector())
                ->activityEvent($this->message(event: 26, eventType: 1)),
        );
    }

    public function testWarnsForNonCanonicalOptionalSessionEvent(): void
    {
        $warning = (new FitActivityImportWarningDetector())
            ->sessionEvent(
                $this->sessionMessage(event: 0, eventType: 0),
            );

        self::assertNotNull($warning);
        self::assertSame(
            ActivityImportWarningCode::UnknownSessionEvent,
            $warning->code,
        );
        self::assertSame('timer', $warning->context['eventName']);
        self::assertSame('start', $warning->context['eventTypeName']);
    }

    public function testAcceptsCanonicalOptionalSessionEvent(): void
    {
        self::assertNull(
            (new FitActivityImportWarningDetector())
                ->sessionEvent(
                    $this->sessionMessage(event: 8, eventType: 1),
                ),
        );
    }

    public function testAcceptsMissingOptionalSessionEventFields(): void
    {
        self::assertNull(
            (new FitActivityImportWarningDetector())
                ->sessionEvent(
                    $this->sessionMessage(event: null, eventType: null),
                ),
        );
    }

    public function testAcceptsPartialCanonicalOptionalSessionEventFields(): void
    {
        $detector = new FitActivityImportWarningDetector();

        self::assertNull(
            $detector->sessionEvent(
                $this->sessionMessage(event: 8, eventType: null),
            ),
        );
        self::assertNull(
            $detector->sessionEvent(
                $this->sessionMessage(event: null, eventType: 1),
            ),
        );
    }

    public function testWarnsForPartialSessionCoordinatePairs(): void
    {
        $detector = new FitActivityImportWarningDetector();
        $startWarning = $detector->sessionPartialStartPosition(
            $this->sessionMessage(
                event: null,
                eventType: null,
                fields: [
                    3 => [0x85, pack('V', 123)],
                ],
            ),
        );
        $endWarning = $detector->sessionPartialEndPosition(
            $this->sessionMessage(
                event: null,
                eventType: null,
                fields: [
                    39 => [0x85, pack('V', 456)],
                ],
            ),
        );

        self::assertNotNull($startWarning);
        self::assertSame(
            ActivityImportWarningCode::SessionCoordinatePairSkipped,
            $startWarning->code,
        );
        self::assertSame(
            [
                'sequence' => 1,
                'byteOffset' => 20,
                'latitudeField' => 'start_position_lat',
                'longitudeField' => 'start_position_long',
                'resolvedField' => 'start_position_lat',
                'unresolvedField' => 'start_position_long',
            ],
            $startWarning->context,
        );

        self::assertNotNull($endWarning);
        self::assertSame(
            'end_position_long',
            $endWarning->context['resolvedField'],
        );
        self::assertSame(
            'end_position_lat',
            $endWarning->context['unresolvedField'],
        );
    }

    public function testInvalidSessionCoordinateCounterpartIsReportedAsUnresolved(): void
    {
        $warning = (new FitActivityImportWarningDetector())
            ->sessionPartialStartPosition(
                $this->sessionMessage(
                    event: null,
                    eventType: null,
                    fields: [
                        3 => [0x85, pack('V', 123)],
                        4 => [0x85, pack('V', 0x7FFFFFFF)],
                    ],
                ),
            );

        self::assertNotNull($warning);
        self::assertSame(
            'start_position_lat',
            $warning->context['resolvedField'],
        );
        self::assertSame(
            'start_position_long',
            $warning->context['unresolvedField'],
        );
        self::assertArrayNotHasKey('missingField', $warning->context);
    }

    public function testWarnsWhenExplicitLapLengthRangeReferencesMissingIndex(): void
    {
        $warnings = (new FitActivityImportWarningDetector())
            ->lapLengthReferenceMismatches(
                details: [
                    $this->poolLengthItem(index: 4),
                    $this->poolLengthItem(index: 6),
                ],
                laps: [
                    $this->lapItem(
                        index: 0,
                        firstLengthIndex: 4,
                        lengthCount: 3,
                    ),
                ],
            );

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::LapLengthReferenceMismatch,
            $warnings[0]->code,
        );
        self::assertSame(4, $warnings[0]->context['firstLengthIndex']);
        self::assertSame(3, $warnings[0]->context['lengthCount']);
        self::assertSame(5, $warnings[0]->context['firstMissingLengthIndex']);
        self::assertSame(1, $warnings[0]->context['missingLengthCount']);
    }

    public function testDoesNotGuessLapLengthMismatchWhenLengthIndexIsMissing(): void
    {
        self::assertSame(
            [],
            (new FitActivityImportWarningDetector())
                ->lapLengthReferenceMismatches(
                    details: [
                        $this->poolLengthItem(index: 4),
                        $this->poolLengthItem(index: null),
                    ],
                    laps: [
                        $this->lapItem(
                            index: 0,
                            firstLengthIndex: 4,
                            lengthCount: 2,
                        ),
                    ],
                ),
        );
    }

    public function testAcceptsCompleteExplicitLapLengthRange(): void
    {
        self::assertSame(
            [],
            (new FitActivityImportWarningDetector())
                ->lapLengthReferenceMismatches(
                    details: [
                        $this->poolLengthItem(index: 8),
                        $this->poolLengthItem(index: 9),
                    ],
                    laps: [
                        $this->lapItem(
                            index: 0,
                            firstLengthIndex: 8,
                            lengthCount: 2,
                        ),
                    ],
                ),
        );
    }

    public function testWarnsWhenLapActiveLengthCountDoesNotMatchReferencedLengths(): void
    {
        $warnings = (new FitActivityImportWarningDetector())
            ->lapActiveLengthCountMismatches(
                details: [
                    $this->poolLengthItem(index: 4),
                    $this->poolLengthItem(
                        index: 5,
                        type: PoolLengthType::Idle,
                    ),
                    $this->poolLengthItem(index: 6),
                ],
                laps: [
                    $this->lapItem(
                        index: 0,
                        firstLengthIndex: 4,
                        lengthCount: 3,
                        activeLengthCount: 3,
                    ),
                ],
            );

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::LapActiveLengthCountMismatch,
            $warnings[0]->code,
        );
        self::assertSame(
            3,
            $warnings[0]->context['declaredActiveLengthCount'],
        );
        self::assertSame(
            2,
            $warnings[0]->context['decodedActiveLengthCount'],
        );
    }

    public function testDoesNotGuessLapActiveLengthCountWhenReferenceRangeIsIncomplete(): void
    {
        self::assertSame(
            [],
            (new FitActivityImportWarningDetector())
                ->lapActiveLengthCountMismatches(
                    details: [
                        $this->poolLengthItem(index: 4),
                        $this->poolLengthItem(index: 6),
                    ],
                    laps: [
                        $this->lapItem(
                            index: 0,
                            firstLengthIndex: 4,
                            lengthCount: 3,
                            activeLengthCount: 2,
                        ),
                    ],
                ),
        );
    }

    public function testWarnsWhenSessionLengthCountsDoNotMatchCompleteReferenceChain(): void
    {
        $warnings = (new FitActivityImportWarningDetector())
            ->sessionLengthCountMismatches(
                details: [
                    $this->poolLengthItem(index: 8),
                    $this->poolLengthItem(
                        index: 9,
                        type: PoolLengthType::Idle,
                    ),
                    $this->poolLengthItem(index: 10),
                ],
                laps: [
                    $this->lapItem(
                        index: 4,
                        firstLengthIndex: 8,
                        lengthCount: 2,
                    ),
                    $this->lapItem(
                        index: 5,
                        firstLengthIndex: 10,
                        lengthCount: 1,
                    ),
                ],
                sessions: [
                    $this->sessionItem(
                        firstLapIndex: 4,
                        lapCount: 2,
                        lengthCount: 4,
                        activeLengthCount: 3,
                    ),
                ],
            );

        self::assertCount(2, $warnings);
        self::assertSame(
            ActivityImportWarningCode::SessionLengthCountMismatch,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'firstLapIndex' => 4,
                'lapCount' => 2,
                'declaredLengthCount' => 4,
                'decodedLengthCount' => 3,
            ],
            $warnings[0]->context,
        );
        self::assertSame(
            ActivityImportWarningCode::SessionActiveLengthCountMismatch,
            $warnings[1]->code,
        );
        self::assertSame(
            [
                'firstLapIndex' => 4,
                'lapCount' => 2,
                'declaredActiveLengthCount' => 3,
                'decodedActiveLengthCount' => 2,
            ],
            $warnings[1]->context,
        );
    }

    public function testDoesNotGuessSessionLengthCountsWhenReferenceChainIsIncomplete(): void
    {
        self::assertSame(
            [],
            (new FitActivityImportWarningDetector())
                ->sessionLengthCountMismatches(
                    details: [
                        $this->poolLengthItem(index: 8),
                        $this->poolLengthItem(index: 10),
                    ],
                    laps: [
                        $this->lapItem(
                            index: 4,
                            firstLengthIndex: 8,
                            lengthCount: 3,
                        ),
                    ],
                    sessions: [
                        $this->sessionItem(
                            firstLapIndex: 4,
                            lapCount: 1,
                            lengthCount: 2,
                            activeLengthCount: 2,
                        ),
                    ],
                ),
        );
    }

    public function testAcceptsMatchingSessionLengthCounts(): void
    {
        self::assertSame(
            [],
            (new FitActivityImportWarningDetector())
                ->sessionLengthCountMismatches(
                    details: [
                        $this->poolLengthItem(index: 8),
                        $this->poolLengthItem(
                            index: 9,
                            type: PoolLengthType::Idle,
                        ),
                    ],
                    laps: [
                        $this->lapItem(
                            index: 4,
                            firstLengthIndex: 8,
                            lengthCount: 2,
                        ),
                    ],
                    sessions: [
                        $this->sessionItem(
                            firstLapIndex: 4,
                            lapCount: 1,
                            lengthCount: 2,
                            activeLengthCount: 1,
                        ),
                    ],
                ),
        );
    }

    public function testWarnsWhenExplicitSessionLapRangeReferencesMissingIndex(): void
    {
        $warnings = (new FitActivityImportWarningDetector())
            ->sessionLapReferenceMismatches(
                laps: [
                    $this->lapItem(index: 0),
                    $this->lapItem(index: 2),
                ],
                sessions: [
                    $this->sessionItem(
                        firstLapIndex: 0,
                        lapCount: 2,
                    ),
                ],
            );

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::SessionLapReferenceMismatch,
            $warnings[0]->code,
        );
        self::assertSame(0, $warnings[0]->context['firstLapIndex']);
        self::assertSame(2, $warnings[0]->context['lapCount']);
        self::assertSame(1, $warnings[0]->context['firstMissingLapIndex']);
        self::assertSame(1, $warnings[0]->context['missingLapCount']);
    }

    public function testDoesNotGuessSessionLapMismatchWhenLapIndexIsMissing(): void
    {
        self::assertSame(
            [],
            (new FitActivityImportWarningDetector())
                ->sessionLapReferenceMismatches(
                    laps: [
                        $this->lapItem(index: 0),
                        $this->lapItem(index: null),
                    ],
                    sessions: [
                        $this->sessionItem(
                            firstLapIndex: 0,
                            lapCount: 2,
                        ),
                    ],
                ),
        );
    }

    public function testAcceptsCompleteExplicitSessionLapRange(): void
    {
        self::assertSame(
            [],
            (new FitActivityImportWarningDetector())
                ->sessionLapReferenceMismatches(
                    laps: [
                        $this->lapItem(index: 4),
                        $this->lapItem(index: 5),
                    ],
                    sessions: [
                        $this->sessionItem(
                            firstLapIndex: 4,
                            lapCount: 2,
                        ),
                    ],
                ),
        );
    }

    private function message(int $event, int $eventType): UnifiedDataMessage
    {
        $fields = [
            253 => [0x86, pack('V', $this->fitTimestamp())],
            0 => [0x86, pack('V', 1_800_000)],
            1 => [0x84, pack('v', 1)],
            3 => [0x00, chr($event)],
            4 => [0x00, chr($eventType)],
        ];
        $definitions = [];
        $rawFields = [];

        foreach ($fields as $number => [$baseType, $bytes]) {
            $definition = StandardFieldDefinition::create(
                fieldNumber: $number,
                size: strlen($bytes),
                baseType: FitBaseType::fromDefinitionByte($baseType),
            );
            $definitions[] = $definition;
            $rawFields[] = new RawStandardField(
                definition: $definition,
                value: RawFieldValue::fromBytes($bytes),
            );
        }

        return FitDecoder::standard(TestFitProfile::load())->decode(
            RawDataMessage::create(
                sequenceNumber: 1,
                byteOffset: 20,
                recordHeaderByte: 0,
                definition: MessageDefinition::create(
                    localMessageNumber: 0,
                    architecture: FitArchitecture::LittleEndian,
                    globalMessageNumber: 34,
                    standardFields: $definitions,
                ),
                standardFields: $rawFields,
            ),
        );
    }

    /** @param array<int, array{0: int, 1: string}> $fields */
    private function sessionMessage(
        ?int $event,
        ?int $eventType,
        array $fields = [],
    ): UnifiedDataMessage {
        if (null !== $event) {
            $fields[0] = [0x00, chr($event)];
        }

        if (null !== $eventType) {
            $fields[1] = [0x00, chr($eventType)];
        }

        $definitions = [];
        $rawFields = [];

        foreach ($fields as $number => [$baseType, $bytes]) {
            $definition = StandardFieldDefinition::create(
                fieldNumber: $number,
                size: strlen($bytes),
                baseType: FitBaseType::fromDefinitionByte($baseType),
            );
            $definitions[] = $definition;
            $rawFields[] = new RawStandardField(
                definition: $definition,
                value: RawFieldValue::fromBytes($bytes),
            );
        }

        return FitDecoder::standard(TestFitProfile::load())->decode(
            RawDataMessage::create(
                sequenceNumber: 1,
                byteOffset: 20,
                recordHeaderByte: 0,
                definition: MessageDefinition::create(
                    localMessageNumber: 0,
                    architecture: FitArchitecture::LittleEndian,
                    globalMessageNumber: 18,
                    standardFields: $definitions,
                ),
                standardFields: $rawFields,
            ),
        );
    }

    private function ambiguousMessage(): UnifiedDataMessage
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 34,
            name: 'activity',
            fields: [
                FieldProfile::create(
                    fieldNumber: 3,
                    name: 'event',
                    typeName: 'event',
                ),
                FieldProfile::create(
                    fieldNumber: 5,
                    name: 'event',
                    typeName: 'event',
                ),
            ],
        );
        $first = StandardFieldDefinition::create(
            fieldNumber: 3,
            size: 1,
            baseType: FitBaseType::fromDefinitionByte(0x00),
        );
        $second = StandardFieldDefinition::create(
            fieldNumber: 5,
            size: 1,
            baseType: FitBaseType::fromDefinitionByte(0x00),
        );

        return (new FitDecoder(
            profiles: new InMemoryFitProfileRegistry($profile),
            types: new GeneratedFitTypeRegistry(TestFitProfile::typesFile()),
        ))->decode(
            RawDataMessage::create(
                sequenceNumber: 1,
                byteOffset: 20,
                recordHeaderByte: 0,
                definition: MessageDefinition::create(
                    localMessageNumber: 0,
                    architecture: FitArchitecture::LittleEndian,
                    globalMessageNumber: 34,
                    standardFields: [$first, $second],
                ),
                standardFields: [
                    new RawStandardField(
                        definition: $first,
                        value: RawFieldValue::fromBytes(chr(26)),
                    ),
                    new RawStandardField(
                        definition: $second,
                        value: RawFieldValue::fromBytes(chr(26)),
                    ),
                ],
            ),
        );
    }

    private function lapItem(
        ?int $index,
        ?int $firstLengthIndex = null,
        ?int $lengthCount = null,
        ?int $activeLengthCount = null,
    ): LapItem {
        $startedAt = $this->instant('2026-08-05T10:30:00Z');
        $finishedAt = $this->instant('2026-08-05T10:31:00Z');

        return new LapItem(
            lap: Lap::create(
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                timerDuration: Duration::between(
                    $startedAt,
                    $finishedAt,
                ),
            ),
            index: $index,
            firstLengthIndex: $firstLengthIndex,
            lengthCount: $lengthCount,
            activeLengthCount: $activeLengthCount,
        );
    }

    private function poolLengthItem(
        ?int $index,
        PoolLengthType $type = PoolLengthType::Active,
    ): ActivityDetailItem {
        $startedAt = $this->instant('2026-08-05T10:30:00Z');
        $finishedAt = $this->instant('2026-08-05T10:30:25Z');

        return new ActivityDetailItem(
            detail: PoolLength::create(
                interval: ActivityInterval::create(
                    startedAt: $startedAt,
                    finishedAt: $finishedAt,
                    timerDuration: Duration::between(
                        $startedAt,
                        $finishedAt,
                    ),
                ),
                type: $type,
            ),
            index: $index,
        );
    }

    private function sessionItem(
        ?int $firstLapIndex,
        ?int $lapCount,
        ?int $lengthCount = null,
        ?int $activeLengthCount = null,
    ): SessionItem {
        $startedAt = $this->instant('2026-08-05T10:30:00Z');
        $finishedAt = $this->instant('2026-08-05T11:00:00Z');

        return new SessionItem(
            session: ActivitySession::create(
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                timerDuration: Duration::between(
                    $startedAt,
                    $finishedAt,
                ),
            ),
            firstLapIndex: $firstLapIndex,
            lapCount: $lapCount,
            lengthCount: $lengthCount,
            activeLengthCount: $activeLengthCount,
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }

    private function fitTimestamp(): int
    {
        return (new \DateTimeImmutable('2026-08-05T10:30:00Z'))
            ->getTimestamp() - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }
}
