<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Detail\Segment\SegmentEffort;
use Youmad\Endurance\Activity\Telemetry\MeasurementOrigin;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitSegmentLapMessageMapper;
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

final class FitSegmentLapMessageMapperTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testMapsSegmentEffortMetadataCoordinatesAndMetrics(): void
    {
        $items = (new FitSegmentLapMessageMapper())->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:00Z',
                    elapsedMilliseconds: 300_500,
                    timerMilliseconds: 295_250,
                ) + [
                    3 => ['base_type' => 0x85, 'bytes' => pack('V', 536_870_912)],
                    4 => ['base_type' => 0x85, 'bytes' => pack('V', 1_073_741_824)],
                    5 => ['base_type' => 0x85, 'bytes' => pack('V', 596_523_236)],
                    6 => ['base_type' => 0x85, 'bytes' => pack('V', 1_014_089_500)],
                    9 => ['base_type' => 0x86, 'bytes' => pack('V', 123_450)],
                    13 => ['base_type' => 0x84, 'bytes' => pack('v', 8_500)],
                    15 => ['base_type' => 0x02, 'bytes' => chr(151)],
                    21 => ['base_type' => 0x84, 'bytes' => pack('v', 210)],
                    23 => ['base_type' => 0x00, 'bytes' => "\x02"],
                    29 => ['base_type' => 0x07, 'bytes' => "Harju climb\0"],
                    32 => ['base_type' => 0x00, 'bytes' => "\x07"],
                    64 => ['base_type' => 0x00, 'bytes' => "\x00"],
                    65 => ['base_type' => 0x07, 'bytes' => "segment-123\0"],
                    83 => ['base_type' => 0x84, 'bytes' => pack('v', 1)],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(
            ActivityDetailItem::class,
            $items[0],
        );

        $effort = $items[0]->detail;

        self::assertInstanceOf(
            SegmentEffort::class,
            $effort,
        );
        self::assertSame('segment-123', $effort->segmentId);
        self::assertSame('Harju climb', $effort->name);
        self::assertSame('end', $effort->status);
        self::assertSame('cycling', $effort->sport);
        self::assertSame('road', $effort->subSport);
        self::assertSame('garmin', $effort->manufacturer);
        self::assertSame(45.0, $effort->startPosition?->latitude);
        self::assertSame(90.0, $effort->startPosition->longitude);
        self::assertSame(
            300_500_000,
            $effort->interval()->elapsedDuration()->toMicroseconds(),
        );
        self::assertSame(
            295_250_000,
            $effort->interval()->timerDuration->toMicroseconds(),
        );

        $distance = $this->scalar(
            readings: $effort->readings(),
            type: 'total_distance',
        );

        self::assertSame(1_234.5, $distance->value);
        self::assertSame('m', $distance->unit->toString());
        self::assertSame(
            MeasurementOrigin::Reported,
            $this->origin(
                readings: $effort->readings(),
                type: 'total_distance',
            ),
        );
        self::assertSame(
            8.5,
            $this->scalar(
                readings: $effort->readings(),
                type: 'average_speed',
            )->value,
        );
        self::assertSame(
            210,
            $this->scalar(
                readings: $effort->readings(),
                type: 'total_ascent',
            )->value,
        );
    }

    public function testMapsSelectedTotalStrokesSubfieldWithoutReinterpretingAsCycles(): void
    {
        $items = (new FitSegmentLapMessageMapper())->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:00Z',
                    elapsedMilliseconds: 60_000,
                    timerMilliseconds: 60_000,
                ) + [
                    10 => ['base_type' => 0x86, 'bytes' => pack('V', 42)],
                    23 => ['base_type' => 0x00, 'bytes' => "\x02"],
                ],
            ),
        );

        self::assertInstanceOf(ActivityDetailItem::class, $items[0]);
        $effort = $items[0]->detail;

        self::assertInstanceOf(SegmentEffort::class, $effort);
        self::assertSame(
            42,
            $this->scalar(
                readings: $effort->readings(),
                type: 'total_strokes',
            )->value,
        );
        self::assertSame(
            'strokes',
            $this->scalar(
                readings: $effort->readings(),
                type: 'total_strokes',
            )->unit->toString(),
        );
        self::assertFalse(
            $this->hasScalar(
                readings: $effort->readings(),
                type: 'total_cycles',
            ),
        );
    }

    public function testMapsTotalCyclesWhenNoProfileSubfieldIsActive(): void
    {
        $items = (new FitSegmentLapMessageMapper())->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:00Z',
                    elapsedMilliseconds: 60_000,
                    timerMilliseconds: 60_000,
                ) + [
                    10 => ['base_type' => 0x86, 'bytes' => pack('V', 42)],
                ],
            ),
        );

        self::assertInstanceOf(ActivityDetailItem::class, $items[0]);
        $effort = $items[0]->detail;

        self::assertInstanceOf(SegmentEffort::class, $effort);
        self::assertSame(
            42,
            $this->scalar(
                readings: $effort->readings(),
                type: 'total_cycles',
            )->value,
        );
        self::assertSame(
            'cycles',
            $this->scalar(
                readings: $effort->readings(),
                type: 'total_cycles',
            )->unit->toString(),
        );
        self::assertFalse(
            $this->hasScalar(
                readings: $effort->readings(),
                type: 'total_strokes',
            ),
        );
    }

    public function testMapsMinimalSegmentWithoutEventFieldsOrMetadata(): void
    {
        $items = (new FitSegmentLapMessageMapper())->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:00Z',
                    elapsedMilliseconds: 60_000,
                    timerMilliseconds: 60_000,
                ),
            ),
        );

        self::assertInstanceOf(ActivityDetailItem::class, $items[0]);
        $effort = $items[0]->detail;

        self::assertInstanceOf(
            SegmentEffort::class,
            $effort,
        );
        self::assertNull($effort->segmentId);
        self::assertNull($effort->name);
        self::assertNull($effort->status);
        self::assertSame([], $effort->readings());
    }

    public function testNormalizesOptionalSegmentTextAtActivityBoundary(): void
    {
        $items = (new FitSegmentLapMessageMapper())->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:00Z',
                    elapsedMilliseconds: 60_000,
                    timerMilliseconds: 60_000,
                ) + [
                    29 => [
                        'base_type' => 0x07,
                        'bytes' => "Flugplatz Schleißheim \0",
                    ],
                    65 => [
                        'base_type' => 0x07,
                        'bytes' => " 5273425\t\0",
                    ],
                ],
            ),
        );

        self::assertInstanceOf(ActivityDetailItem::class, $items[0]);
        $effort = $items[0]->detail;

        self::assertInstanceOf(SegmentEffort::class, $effort);
        self::assertSame('Flugplatz Schleißheim', $effort->name);
        self::assertSame('5273425', $effort->segmentId);
    }

    public function testMapsBlankOptionalSegmentTextAsMissing(): void
    {
        $items = (new FitSegmentLapMessageMapper())->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:00Z',
                    elapsedMilliseconds: 60_000,
                    timerMilliseconds: 60_000,
                ) + [
                    29 => ['base_type' => 0x07, 'bytes' => " \0"],
                    65 => ['base_type' => 0x07, 'bytes' => "\t\0"],
                ],
            ),
        );

        self::assertInstanceOf(ActivityDetailItem::class, $items[0]);
        $effort = $items[0]->detail;

        self::assertInstanceOf(SegmentEffort::class, $effort);
        self::assertNull($effort->name);
        self::assertNull($effort->segmentId);
    }

    public function testSkipsPartialStartPositionAndPreservesMetrics(): void
    {
        $items = (new FitSegmentLapMessageMapper())->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:00Z',
                    elapsedMilliseconds: 60_000,
                    timerMilliseconds: 60_000,
                ) + [
                    3 => ['base_type' => 0x85, 'bytes' => pack('V', 536_870_912)],
                    9 => ['base_type' => 0x86, 'bytes' => pack('V', 12_345)],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(ActivityDetailItem::class, $items[0]);
        $effort = $items[0]->detail;
        self::assertInstanceOf(SegmentEffort::class, $effort);
        self::assertNull($effort->startPosition);
        self::assertSame(
            123.45,
            $this->scalar(
                readings: $effort->readings(),
                type: 'total_distance',
            )->value,
        );
    }

    public function testStandardMapperWarnsWhenPartialSegmentPositionIsSkipped(): void
    {
        $mapper = FitActivityImportItemMapper::standard();
        $items = $mapper->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:00Z',
                    elapsedMilliseconds: 60_000,
                    timerMilliseconds: 60_000,
                ) + [
                    5 => ['base_type' => 0x85, 'bytes' => pack('V', 596_523_236)],
                    6 => ['base_type' => 0x85, 'bytes' => pack('V', 0x7FFFFFFF)],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(ActivityDetailItem::class, $items[0]);
        $effort = $items[0]->detail;
        self::assertInstanceOf(SegmentEffort::class, $effort);
        self::assertNull($effort->endPosition);

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::SegmentCoordinatePairSkipped,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'sequence' => 1,
                'byteOffset' => 20,
                'latitudeField' => 'end_position_lat',
                'longitudeField' => 'end_position_long',
                'resolvedField' => 'end_position_lat',
                'unresolvedField' => 'end_position_long',
            ],
            $warnings[0]->context,
        );
    }

    /**
     * @return array<int, array{base_type: int, bytes: string}>
     */
    private function timingFields(
        string $startedAt,
        int $elapsedMilliseconds,
        int $timerMilliseconds,
    ): array {
        $startedAtValue = $this->fitTimestamp($startedAt);

        return [
            253 => [
                'base_type' => 0x86,
                'bytes' => pack(
                    'V',
                    $startedAtValue
                        + intdiv($elapsedMilliseconds, 1000),
                ),
            ],
            2 => [
                'base_type' => 0x86,
                'bytes' => pack('V', $startedAtValue),
            ],
            7 => [
                'base_type' => 0x86,
                'bytes' => pack('V', $elapsedMilliseconds),
            ],
            8 => [
                'base_type' => 0x86,
                'bytes' => pack('V', $timerMilliseconds),
            ],
        ];
    }

    /**
     * @param array<int, array{base_type: int, bytes: string}> $fields
     */
    private function message(array $fields): UnifiedDataMessage
    {
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
            globalMessageNumber: 142,
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

    /** @param list<MeasurementReading> $readings */
    private function scalar(
        array $readings,
        string $type,
    ): ScalarMeasurement {
        foreach ($readings as $reading) {
            if ($type !== $reading->measurement->type()->toString()) {
                continue;
            }

            self::assertInstanceOf(
                ScalarMeasurement::class,
                $reading->measurement,
            );

            return $reading->measurement;
        }

        self::fail(
            sprintf('Measurement %s was not found.', $type),
        );
    }

    /** @param list<MeasurementReading> $readings */
    private function hasScalar(
        array $readings,
        string $type,
    ): bool {
        foreach ($readings as $reading) {
            if ($type === $reading->measurement->type()->toString()) {
                return true;
            }
        }

        return false;
    }

    /** @param list<MeasurementReading> $readings */
    private function origin(
        array $readings,
        string $type,
    ): MeasurementOrigin {
        foreach ($readings as $reading) {
            if ($type === $reading->measurement->type()->toString()) {
                return $reading->origin;
            }
        }

        self::fail(
            sprintf('Measurement %s was not found.', $type),
        );
    }

    private function fitTimestamp(string $value): int
    {
        return (new \DateTimeImmutable($value))->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }
}
