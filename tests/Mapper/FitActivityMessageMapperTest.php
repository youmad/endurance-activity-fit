<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivitySummaryItem;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityMessageMapper;
use Youmad\Endurance\ActivityFit\Tests\Fixture\TestFitProfile;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\FitBaseType;
use Youmad\Endurance\Fit\Raw\MessageDefinition;
use Youmad\Endurance\Fit\Raw\RawDataMessage;
use Youmad\Endurance\Fit\Raw\RawFieldValue;
use Youmad\Endurance\Fit\Raw\RawStandardField;
use Youmad\Endurance\Fit\Raw\StandardFieldDefinition;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final class FitActivityMessageMapperTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testMapsActivitySummary(): void
    {
        $items = (new FitActivityMessageMapper())->map(
            $this->message(
                timestamp: '2026-01-15T11:00:03Z',
                timerMilliseconds: 3_450_250,
                sessionCount: 2,
                type: 1,
                event: 26,
                eventType: 1,
            ),
        );

        self::assertCount(1, $items);

        $item = $items[0];

        self::assertInstanceOf(
            ActivitySummaryItem::class,
            $item,
        );

        self::assertSame(
            3_450_250_000,
            $item->summary
                ->timerDuration
                ->toMicroseconds(),
        );

        self::assertSame(
            2,
            $item->summary->sessionCount,
        );

        self::assertSame(
            'auto_multi_sport',
            $item->summary->type,
        );

        self::assertSame(
            TemporalResolution::Second,
            $item->timelineResolution,
        );
        self::assertSame(
            0,
            $item->summary->localTimeOffsetSeconds,
        );

        self::assertSame(
            '2026-01-15T11:00:03+00:00',
            $item->summary
                ->reportedAt
                ->toDateTimeImmutable()
                ->format(DATE_ATOM),
        );
    }

    public function testReadsIncompleteActivitySummaryForStreamRecovery(): void
    {
        $source = (new FitActivityMessageMapper())->read(
            $this->message(
                timestamp: '1989-12-31T00:30:01Z',
                timerMilliseconds: null,
                sessionCount: null,
                type: 0,
                event: 26,
                eventType: 1,
            ),
        );

        self::assertNull($source->timerDuration);
        self::assertNull($source->sessionCount);
        self::assertSame('manual', $source->type);
        self::assertSame(
            '1989-12-31T00:30:01+00:00',
            $source->reportedAt
                ->toDateTimeImmutable()
                ->format(DATE_ATOM),
        );
    }

    public function testReadsZeroSessionCountForStreamRecovery(): void
    {
        $source = (new FitActivityMessageMapper())->read(
            $this->message(
                timestamp: '1989-12-31T00:30:01Z',
                timerMilliseconds: 559_000,
                sessionCount: 0,
                type: 0,
                event: 26,
                eventType: 1,
            ),
        );

        self::assertSame(0, $source->sessionCount);
    }

    public function testMapsLocalTimestampToExactOffsetSeconds(): void
    {
        $items = (new FitActivityMessageMapper())->map(
            $this->message(
                timestamp: '2026-01-15T11:00:03Z',
                timerMilliseconds: 1_800_000,
                sessionCount: 1,
                type: 0,
                event: null,
                eventType: null,
                localTimeOffsetSeconds: -25_200,
            ),
        );

        self::assertInstanceOf(ActivitySummaryItem::class, $items[0]);
        self::assertSame(
            -25_200,
            $items[0]->summary->localTimeOffsetSeconds,
        );
    }

    /** @return iterable<string, array{int}> */
    public static function validGarminLocalTimestampOffsets(): iterable
    {
        yield 'Minimum offset' => [-43_200];
        yield 'Maximum offset' => [50_400];
    }

    #[DataProvider('validGarminLocalTimestampOffsets')]
    public function testAcceptsGarminLocalTimestampOffsetBoundaries(
        int $offsetSeconds,
    ): void {
        $items = (new FitActivityMessageMapper())->map(
            $this->message(
                timestamp: '2026-01-15T11:00:03Z',
                timerMilliseconds: 1_800_000,
                sessionCount: 1,
                type: 0,
                event: null,
                eventType: null,
                localTimeOffsetSeconds: $offsetSeconds,
            ),
        );

        self::assertInstanceOf(ActivitySummaryItem::class, $items[0]);
        self::assertSame(
            $offsetSeconds,
            $items[0]->summary->localTimeOffsetSeconds,
        );
    }

    public function testRejectsMissingGarminActivityLocalTimestamp(): void
    {
        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage(
            'Activity Message Local Timestamp is Valid',
        );
        $this->expectExceptionMessage('Local Timestamp is null.');

        (new FitActivityMessageMapper())->map(
            $this->message(
                timestamp: '2026-01-15T11:00:03Z',
                timerMilliseconds: 1_800_000,
                sessionCount: 1,
                type: 0,
                event: null,
                eventType: null,
                localTimeOffsetSeconds: null,
            ),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function invalidGarminLocalTimestampOffsets(): iterable
    {
        yield 'Below minimum offset' => [-43_201];
        yield 'Above maximum offset' => [50_401];
    }

    #[DataProvider('invalidGarminLocalTimestampOffsets')]
    public function testRejectsLocalTimestampOutsideGarminRange(
        int $offsetSeconds,
    ): void {
        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage(
            'Activity Message Local Timestamp is Valid',
        );
        $this->expectExceptionMessage(
            sprintf('offset of %d seconds', $offsetSeconds),
        );

        (new FitActivityMessageMapper())->map(
            $this->message(
                timestamp: '2026-01-15T11:00:03Z',
                timerMilliseconds: 1_800_000,
                sessionCount: 1,
                type: 0,
                event: null,
                eventType: null,
                localTimeOffsetSeconds: $offsetSeconds,
            ),
        );
    }

    public function testStandardMapperIncludesActivitySummaryMapper(): void
    {
        $items = FitActivityImportItemMapper::standard()->map(
            $this->message(
                timestamp: '2026-01-15T11:00:03Z',
                timerMilliseconds: 1_800_000,
                sessionCount: 1,
                type: 0,
                event: 26,
                eventType: 1,
            ),
        );

        self::assertCount(1, $items);

        self::assertInstanceOf(
            ActivitySummaryItem::class,
            $items[0],
        );
    }

    public function testMapsActivityWithoutOptionalFields(): void
    {
        $items = (new FitActivityMessageMapper())->map(
            $this->message(
                timestamp: '2026-01-15T11:00:03Z',
                timerMilliseconds: 1_800_000,
                sessionCount: 1,
                type: null,
                event: null,
                eventType: null,
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(
            ActivitySummaryItem::class,
            $items[0],
        );
        self::assertNull($items[0]->summary->type);
    }

    public function testIgnoresNonCanonicalOptionalEventFields(): void
    {
        $items = (new FitActivityMessageMapper())->map(
            $this->message(
                timestamp: '2026-01-15T11:00:03Z',
                timerMilliseconds: 1_800_000,
                sessionCount: 1,
                type: 1,
                event: 1,
                eventType: 1,
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(ActivitySummaryItem::class, $items[0]);
        self::assertSame(
            'auto_multi_sport',
            $items[0]->summary->type,
        );
    }

    public function testRejectsZeroSessionCount(): void
    {
        $this->expectException(
            InvalidFitActivityMessage::class,
        );

        $this->expectExceptionMessage(
            'num_sessions must be an integer between 1 and 65535',
        );

        (new FitActivityMessageMapper())->map(
            $this->message(
                timestamp: '2026-01-15T11:00:03Z',
                timerMilliseconds: 1_800_000,
                sessionCount: 0,
                type: 0,
                event: 26,
                eventType: 1,
            ),
        );
    }

    private function message(
        string $timestamp,
        ?int $timerMilliseconds,
        ?int $sessionCount,
        ?int $type,
        ?int $event,
        ?int $eventType,
        ?int $localTimeOffsetSeconds = 0,
    ): UnifiedDataMessage {
        $fields = [
            253 => [
                'base_type' => 0x86,
                'bytes' => pack(
                    'V',
                    $this->fitTimestamp($timestamp),
                ),
            ],
        ];

        if (null !== $localTimeOffsetSeconds) {
            $fields[5] = [
                'base_type' => 0x86,
                'bytes' => pack(
                    'V',
                    $this->fitTimestamp($timestamp)
                        + $localTimeOffsetSeconds,
                ),
            ];
        }

        if (null !== $timerMilliseconds) {
            $fields[0] = [
                'base_type' => 0x86,
                'bytes' => pack(
                    'V',
                    $timerMilliseconds,
                ),
            ];
        }

        if (null !== $sessionCount) {
            $fields[1] = [
                'base_type' => 0x84,
                'bytes' => pack('v', $sessionCount),
            ];
        }

        foreach (
            [
                2 => $type,
                3 => $event,
                4 => $eventType,
            ] as $fieldNumber => $value
        ) {
            if (null === $value) {
                continue;
            }

            $fields[$fieldNumber] = [
                'base_type' => 0x00,
                'bytes' => chr($value),
            ];
        }

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
            globalMessageNumber: 34,
            standardFields: $definitions,
        );

        return FitDecoder::standard(TestFitProfile::load())->decode(
            RawDataMessage::create(
                sequenceNumber: 1,
                byteOffset: 20,
                recordHeaderByte: 0x00,
                definition: $definition,
                standardFields: $rawFields,
            ),
        );
    }

    private function fitTimestamp(string $value): int
    {
        return (
            new \DateTimeImmutable($value)
        )->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }
}
