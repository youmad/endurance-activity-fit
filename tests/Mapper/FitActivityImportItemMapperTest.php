<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Youmad\Endurance\Activity\Application\Import\ActivitySummaryItem;
use Youmad\Endurance\Activity\Application\Import\DeviceItem;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\Summary\ActivitySummary;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityMessageMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitMessageMapper;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Profile\FieldProfile;
use Youmad\Endurance\Fit\Profile\InMemoryFitProfileRegistry;
use Youmad\Endurance\Fit\Profile\InMemoryFitTypeRegistry;
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

final class FitActivityImportItemMapperTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testMapsStreamLazilyAndExpandsSupportedMessages(): void
    {
        $fileIdProfile = MessageProfile::create(
            globalMessageNumber: 0,
            name: 'file_id',
            fields: [
                FieldProfile::create(
                    fieldNumber: 0,
                    name: 'type',
                    typeName: 'file',
                ),
            ],
        );

        $recordProfile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                FieldProfile::create(
                    fieldNumber: 253,
                    name: 'timestamp',
                    typeName: 'date_time',
                    units: 's',
                ),
                FieldProfile::create(
                    fieldNumber: 3,
                    name: 'heart_rate',
                    typeName: 'uint8',
                    units: 'bpm',
                ),
            ],
        );

        $fileId = $this->message(
            profile: $fileIdProfile,
            allProfiles: [
                $fileIdProfile,
                $recordProfile,
            ],
            fields: [
                0 => [
                    'base_type' => 0x00,
                    'bytes' => "\x04",
                ],
            ],
        );

        $record = $this->message(
            profile: $recordProfile,
            allProfiles: [
                $fileIdProfile,
                $recordProfile,
            ],
            fields: [
                253 => [
                    'base_type' => 0x86,
                    'bytes' => pack(
                        'V',
                        (
                            new \DateTimeImmutable(
                                '2026-01-15T10:30:01Z',
                            )
                        )->getTimestamp()
                            - self::FIT_EPOCH_TO_UNIX_SECONDS,
                    ),
                ],
                3 => [
                    'base_type' => 0x02,
                    'bytes' => chr(150),
                ],
            ],
        );

        /** @var \ArrayObject<int, string> $log */
        $log = new \ArrayObject();

        $stream = (
            static function () use (
                $log,
                $fileId,
                $record,
            ): iterable {
                $log[] = 'file_id';

                yield 1 => $fileId;

                $log[] = 'record';

                yield 2 => $record;
            }
        )();

        $mapped = FitActivityImportItemMapper::standard()
            ->mapStream($stream);

        self::assertSame(
            [],
            $log->getArrayCopy(),
        );

        $items = iterator_to_array($mapped);

        self::assertSame(
            [
                'file_id',
                'record',
            ],
            $log->getArrayCopy(),
        );

        self::assertSame(
            [0, 1, 2, 3],
            array_keys($items),
        );

        self::assertInstanceOf(
            DeviceItem::class,
            $items[0],
        );

        self::assertInstanceOf(
            ObservationItem::class,
            $items[1],
        );

        self::assertInstanceOf(
            LapItem::class,
            $items[2],
        );

        self::assertInstanceOf(
            SessionItem::class,
            $items[3],
        );
    }

    public function testStreamMergesLifecycleEventsSerializedBeforeObservationsByTimestamp(): void
    {
        $startedAt = $this->instant('2026-01-15T10:30:00Z');
        $finishedAt = $this->instant('2026-01-15T10:30:10Z');
        $profiles = [
            MessageProfile::create(
                globalMessageNumber: 200,
                name: 'timer_start',
            ),
            MessageProfile::create(
                globalMessageNumber: 201,
                name: 'timer_pause',
            ),
            MessageProfile::create(
                globalMessageNumber: 202,
                name: 'first_record',
            ),
            MessageProfile::create(
                globalMessageNumber: 203,
                name: 'last_record',
            ),
        ];
        $mappedItems = [
            200 => [new ActivityLifecycleItem(
                action: ActivityLifecycleAction::Start,
                occurredAt: $startedAt,
            )],
            201 => [new ActivityLifecycleItem(
                action: ActivityLifecycleAction::Pause,
                occurredAt: $finishedAt,
            )],
            202 => [new ObservationItem(
                $this->observation($startedAt),
            )],
            203 => [new ObservationItem(
                $this->observation($finishedAt),
            )],
        ];
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($mappedItems) implements FitMessageMapper {
                /**
                 * @param array<int, list<ActivityImportItem>> $items
                 */
                public function __construct(
                    private readonly array $items,
                ) {
                }

                public function supports(
                    UnifiedDataMessage $message,
                ): bool {
                    return isset(
                        $this->items[$message->globalMessageNumber()],
                    );
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return $this->items[
                        $message->globalMessageNumber()
                    ];
                }
            }],
        );
        $messages = array_map(
            fn (MessageProfile $profile): UnifiedDataMessage => $this->message(
                profile: $profile,
                allProfiles: $profiles,
                fields: [],
            ),
            $profiles,
        );

        $pointItems = array_values(
            array_filter(
                iterator_to_array(
                    $mapper->mapStream($messages),
                    false,
                ),
                static fn (ActivityImportItem $item): bool => $item instanceof ActivityLifecycleItem
                    || $item instanceof ObservationItem,
            ),
        );

        self::assertCount(4, $pointItems);
        self::assertInstanceOf(
            ActivityLifecycleItem::class,
            $pointItems[0],
        );
        self::assertSame(
            ActivityLifecycleAction::Start,
            $pointItems[0]->action,
        );
        self::assertInstanceOf(ObservationItem::class, $pointItems[1]);
        self::assertTrue(
            $pointItems[1]->observation->timestamp->equals($startedAt),
        );
        self::assertInstanceOf(
            ActivityLifecycleItem::class,
            $pointItems[2],
        );
        self::assertSame(
            ActivityLifecycleAction::Pause,
            $pointItems[2]->action,
        );
        self::assertInstanceOf(ObservationItem::class, $pointItems[3]);
        self::assertTrue(
            $pointItems[3]->observation->timestamp->equals($finishedAt),
        );
    }

    public function testStreamRecoversIncompleteActivitySummaryFromSessions(): void
    {
        $activityProfile = MessageProfile::create(
            globalMessageNumber: 34,
            name: 'activity',
            fields: [
                FieldProfile::create(
                    fieldNumber: 253,
                    name: 'timestamp',
                    typeName: 'date_time',
                    units: 's',
                ),
                FieldProfile::create(
                    fieldNumber: 5,
                    name: 'local_timestamp',
                    typeName: 'local_date_time',
                ),
            ],
        );
        $sessionProfile = MessageProfile::create(
            globalMessageNumber: 18,
            name: 'session',
        );
        $profiles = [$activityProfile, $sessionProfile];
        $activity = $this->message(
            profile: $activityProfile,
            allProfiles: $profiles,
            fields: [
                253 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', 1_801),
                ],
                5 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', 1_801),
                ],
            ],
        );
        $sessionMessage = $this->message(
            profile: $sessionProfile,
            allProfiles: $profiles,
            fields: [],
        );
        $session = new SessionItem(
            ActivitySession::create(
                startedAt: $this->instant(
                    '1989-12-31T00:20:00Z',
                ),
                finishedAt: $this->instant(
                    '1989-12-31T00:30:00Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    559_000_000,
                ),
            ),
        );
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($session) implements FitMessageMapper {
                public function __construct(
                    private readonly SessionItem $session,
                ) {
                }

                public function supports(
                    UnifiedDataMessage $message,
                ): bool {
                    return 18 === $message->globalMessageNumber();
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return [$this->session];
                }
            }],
            activityMessages: new FitActivityMessageMapper(),
        );

        $items = iterator_to_array(
            $mapper->mapStream([$activity, $sessionMessage]),
            false,
        );

        self::assertCount(2, $items);
        self::assertInstanceOf(SessionItem::class, $items[0]);
        self::assertInstanceOf(ActivitySummaryItem::class, $items[1]);
        self::assertSame(1, $items[1]->summary->sessionCount);
        self::assertSame(
            559_000_000,
            $items[1]->summary->timerDuration->toMicroseconds(),
        );
        self::assertSame(
            ActivityImportWarningCode::ActivitySummaryFieldsRecovered,
            $mapper->warnings()[0]->code,
        );
    }

    public function testMissingSummaryRecoveryRespectsExplicitTimerStart(): void
    {
        $preStartAt = $this->instant('2026-01-15T10:29:59Z');
        $startedAt = $this->instant('2026-01-15T10:30:00Z');
        $finishedAt = $this->instant('2026-01-15T10:30:10Z');
        $profiles = [
            MessageProfile::create(
                globalMessageNumber: 200,
                name: 'pre_start_record',
            ),
            MessageProfile::create(
                globalMessageNumber: 201,
                name: 'timer_start',
            ),
            MessageProfile::create(
                globalMessageNumber: 202,
                name: 'record',
            ),
            MessageProfile::create(
                globalMessageNumber: 203,
                name: 'activity_summary',
            ),
        ];
        $mappedItems = [
            200 => [new ObservationItem(
                $this->observation($preStartAt),
            )],
            201 => [new ActivityLifecycleItem(
                action: ActivityLifecycleAction::Start,
                occurredAt: $startedAt,
            )],
            202 => [new ObservationItem(
                $this->observation($finishedAt),
            )],
            203 => [new ActivitySummaryItem(
                summary: ActivitySummary::create(
                    reportedAt: $finishedAt,
                    timerDuration: Duration::between(
                        $startedAt,
                        $finishedAt,
                    ),
                    sessionCount: 1,
                ),
            )],
        ];
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($mappedItems) implements FitMessageMapper {
                /**
                 * @param array<int, list<ActivityImportItem>> $items
                 */
                public function __construct(
                    private readonly array $items,
                ) {
                }

                public function supports(
                    UnifiedDataMessage $message,
                ): bool {
                    return isset(
                        $this->items[$message->globalMessageNumber()],
                    );
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return $this->items[
                        $message->globalMessageNumber()
                    ];
                }
            }],
        );
        $messages = array_map(
            fn (MessageProfile $profile): UnifiedDataMessage => $this->message(
                profile: $profile,
                allProfiles: $profiles,
                fields: [],
            ),
            $profiles,
        );

        $items = iterator_to_array(
            $mapper->mapStream($messages),
            false,
        );
        $sessions = array_values(
            array_filter(
                $items,
                static fn (object $item): bool => $item instanceof SessionItem,
            ),
        );

        self::assertCount(1, $sessions);
        self::assertTrue(
            $sessions[0]->session->startedAt->equals($startedAt),
        );
        self::assertSame(
            10_000_000,
            $sessions[0]
                ->session
                ->timerDuration
                ->toMicroseconds(),
        );
    }

    private function observation(Instant $timestamp): ActivityObservation
    {
        return ActivityObservation::create(
            $timestamp,
            new ScalarMeasurement(
                measurementType: MeasurementType::fromString('heart_rate'),
                value: 150,
                unit: MeasurementUnit::fromSymbol('bpm'),
            ),
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }

    /**
     * @param non-empty-list<MessageProfile> $allProfiles
     * @param array<
     *     int,
     *     array{
     *         base_type: int,
     *         bytes: string
     *     }
     * > $fields
     */
    private function message(
        MessageProfile $profile,
        array $allProfiles,
        array $fields,
    ): UnifiedDataMessage {
        $definitions = [];
        $rawFields = [];

        foreach ($fields as $fieldNumber => $field) {
            $definition = StandardFieldDefinition::create(
                fieldNumber: $fieldNumber,
                size: strlen($field['bytes']),
                baseType: FitBaseType::fromDefinitionByte(
                    $field['base_type'],
                ),
            );

            $definitions[] = $definition;

            $rawFields[] = new RawStandardField(
                definition: $definition,
                value: RawFieldValue::fromBytes(
                    $field['bytes'],
                ),
            );
        }

        $definition = MessageDefinition::create(
            localMessageNumber: 0,
            architecture: FitArchitecture::LittleEndian,
            globalMessageNumber: $profile->globalMessageNumber,
            standardFields: $definitions,
        );

        $raw = RawDataMessage::create(
            sequenceNumber: 1,
            byteOffset: 20,
            recordHeaderByte: 0x00,
            definition: $definition,
            standardFields: $rawFields,
        );

        return (
            new FitDecoder(
                profiles: new InMemoryFitProfileRegistry(
                    ...$allProfiles,
                ),
                types: new InMemoryFitTypeRegistry(),
            )
        )->decode($raw);
    }
}
