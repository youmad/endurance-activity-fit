<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\Activity\Telemetry\MeasurementOrigin;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\ValueObject\SummaryAdjacencyPolicy;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitSessionMessageMapper;
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

final class FitSessionMessageMapperTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testMapsSessionTimingDisciplinePositionsAndSummaryMeasurements(): void
    {
        $items = (new FitSessionMessageMapper())->map(
            $this->message(
                fields: [
                    253 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T11:00:00Z'))],
                    2 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T10:30:00Z'))],
                    3 => ['base_type' => 0x85, 'bytes' => $this->sint32($this->semicircles(59.4369))],
                    4 => ['base_type' => 0x85, 'bytes' => $this->sint32($this->semicircles(24.7535))],
                    5 => ['base_type' => 0x00, 'bytes' => "\x02"],
                    6 => ['base_type' => 0x00, 'bytes' => "\x07"],
                    7 => ['base_type' => 0x86, 'bytes' => pack('V', 1_800_000)],
                    8 => ['base_type' => 0x86, 'bytes' => pack('V', 1_700_000)],
                    9 => ['base_type' => 0x86, 'bytes' => pack('V', 1_234_500)],
                    16 => ['base_type' => 0x02, 'bytes' => chr(150)],
                    25 => ['base_type' => 0x84, 'bytes' => pack('v', 2)],
                    26 => ['base_type' => 0x84, 'bytes' => pack('v', 3)],
                    33 => ['base_type' => 0x84, 'bytes' => pack('v', 12)],
                    38 => ['base_type' => 0x85, 'bytes' => $this->sint32($this->semicircles(59.5))],
                    39 => ['base_type' => 0x85, 'bytes' => $this->sint32($this->semicircles(24.8))],
                    47 => ['base_type' => 0x84, 'bytes' => pack('v', 10)],
                    124 => ['base_type' => 0x86, 'bytes' => pack('V', 8_510)],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(SessionItem::class, $items[0]);
        self::assertSame(SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds, $items[0]->session->adjacencyPolicy);

        $item = $items[0];
        $session = $item->session;

        self::assertSame(2, $item->firstLapIndex);
        self::assertSame(3, $item->lapCount);
        self::assertSame(12, $item->lengthCount);
        self::assertSame(10, $item->activeLengthCount);
        self::assertSame('cycling', $session->sport);
        self::assertSame('road', $session->subSport);
        self::assertSame(
            1_800_000_000,
            $session->elapsedDuration()->toMicroseconds(),
        );
        self::assertSame(
            1_700_000_000,
            $session->timerDuration->toMicroseconds(),
        );
        self::assertSame(
            TemporalResolution::Second,
            $session->timelineResolution,
        );
        self::assertEqualsWithDelta(
            59.4369,
            $session->startPosition?->latitude,
            0.0000001,
        );
        self::assertEqualsWithDelta(
            24.8,
            $session->endPosition?->longitude,
            0.0000001,
        );

        $distance = $this->reading($session->readings(), 'total_distance');
        self::assertInstanceOf(
            ScalarMeasurement::class,
            $distance->measurement,
        );
        self::assertSame(12_345, $distance->measurement->value);
        self::assertSame(MeasurementOrigin::Reported, $distance->origin);

        $speed = $this->reading($session->readings(), 'average_speed');
        self::assertInstanceOf(
            ScalarMeasurement::class,
            $speed->measurement,
        );
        self::assertSame(8.51, $speed->measurement->value);

        $laps = $this->reading($session->readings(), 'lap_count');
        self::assertInstanceOf(
            ScalarMeasurement::class,
            $laps->measurement,
        );
        self::assertSame(3, $laps->measurement->value);
    }

    public function testPreservesProfileSelectedSessionSubfieldMeasurementSemantics(): void
    {
        $this->assertSessionScalarMeasurement(
            fields: [
                5 => ['base_type' => 0x00, 'bytes' => "\x01"],
                10 => ['base_type' => 0x86, 'bytes' => pack('V', 12_345)],
            ],
            measurementType: 'total_strides',
            value: 12_345,
            unit: 'strides',
        );
        $this->assertSessionScalarMeasurement(
            fields: [
                5 => ['base_type' => 0x00, 'bytes' => "\x05"],
                10 => ['base_type' => 0x86, 'bytes' => pack('V', 4_321)],
            ],
            measurementType: 'total_strokes',
            value: 4_321,
            unit: 'strokes',
        );
        $this->assertSessionScalarMeasurement(
            fields: [
                5 => ['base_type' => 0x00, 'bytes' => "\x3e"],
                10 => ['base_type' => 0x86, 'bytes' => pack('V', 88)],
            ],
            measurementType: 'total_reps',
            value: 88,
            unit: 'reps',
        );
        $this->assertSessionScalarMeasurement(
            fields: [
                5 => ['base_type' => 0x00, 'bytes' => "\x42"],
                10 => ['base_type' => 0x86, 'bytes' => pack('V', 777)],
            ],
            measurementType: 'total_pushes',
            value: 777,
            unit: 'pushes',
        );
        $this->assertSessionScalarMeasurement(
            fields: [
                5 => ['base_type' => 0x00, 'bytes' => "\x00"],
                10 => ['base_type' => 0x86, 'bytes' => pack('V', 321)],
            ],
            measurementType: 'total_cycles',
            value: 321,
            unit: 'cycles',
        );
        $this->assertSessionScalarMeasurement(
            fields: [
                5 => ['base_type' => 0x00, 'bytes' => "\x01"],
                18 => ['base_type' => 0x02, 'bytes' => chr(172)],
            ],
            measurementType: 'average_running_cadence',
            value: 172,
            unit: 'strides/min',
        );
        $this->assertSessionScalarMeasurement(
            fields: [
                5 => ['base_type' => 0x00, 'bytes' => "\x01"],
                19 => ['base_type' => 0x02, 'bytes' => chr(188)],
            ],
            measurementType: 'maximum_running_cadence',
            value: 188,
            unit: 'strides/min',
        );
    }

    public function testStandardMapperIncludesSessionMapper(): void
    {
        $items = FitActivityImportItemMapper::standard()->map(
            $this->message(
                fields: [
                    253 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T11:00:00Z'))],
                    2 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T10:30:00Z'))],
                    7 => ['base_type' => 0x86, 'bytes' => pack('V', 1_800_000)],
                    8 => ['base_type' => 0x86, 'bytes' => pack('V', 1_700_000)],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(SessionItem::class, $items[0]);
    }

    public function testStandardMapperWarnsWhenPartialSessionPositionIsSkipped(): void
    {
        $mapper = FitActivityImportItemMapper::standard();
        $items = $mapper->map(
            $this->message(
                fields: [
                    253 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T11:00:00Z'))],
                    2 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T10:30:00Z'))],
                    3 => ['base_type' => 0x85, 'bytes' => $this->sint32($this->semicircles(59.4369))],
                    7 => ['base_type' => 0x86, 'bytes' => pack('V', 1_800_000)],
                    8 => ['base_type' => 0x86, 'bytes' => pack('V', 1_700_000)],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(SessionItem::class, $items[0]);
        self::assertNull($items[0]->session->startPosition);

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::SessionCoordinatePairSkipped,
            $warnings[0]->code,
        );
        self::assertSame(
            'start_position_lat',
            $warnings[0]->context['resolvedField'],
        );
        self::assertSame(
            'start_position_long',
            $warnings[0]->context['unresolvedField'],
        );
    }

    public function testSkipsPartialStartPosition(): void
    {
        $items = (new FitSessionMessageMapper())->map(
            $this->message(
                fields: [
                    253 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T11:00:00Z'))],
                    2 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T10:30:00Z'))],
                    3 => ['base_type' => 0x85, 'bytes' => $this->sint32($this->semicircles(59.4369))],
                    7 => ['base_type' => 0x86, 'bytes' => pack('V', 1_800_000)],
                    8 => ['base_type' => 0x86, 'bytes' => pack('V', 1_700_000)],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(SessionItem::class, $items[0]);
        self::assertNull($items[0]->session->startPosition);
    }

    public function testSkipsStartPositionWithInvalidCounterpart(): void
    {
        $items = (new FitSessionMessageMapper())->map(
            $this->message(
                fields: [
                    253 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T11:00:00Z'))],
                    2 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T10:30:00Z'))],
                    3 => ['base_type' => 0x85, 'bytes' => $this->sint32($this->semicircles(59.4369))],
                    4 => ['base_type' => 0x85, 'bytes' => pack('V', 0x7FFFFFFF)],
                    7 => ['base_type' => 0x86, 'bytes' => pack('V', 1_800_000)],
                    8 => ['base_type' => 0x86, 'bytes' => pack('V', 1_700_000)],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(SessionItem::class, $items[0]);
        self::assertNull($items[0]->session->startPosition);
    }

    /**
     * @param array<int, array{base_type: int, bytes: string}> $fields
     */
    private function assertSessionScalarMeasurement(
        array $fields,
        string $measurementType,
        int|float $value,
        string $unit,
    ): void {
        $items = (new FitSessionMessageMapper())->map(
            $this->message(
                [
                    253 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T11:00:00Z'))],
                    2 => ['base_type' => 0x86, 'bytes' => pack('V', $this->fitTimestamp('2026-01-15T10:30:00Z'))],
                    7 => ['base_type' => 0x86, 'bytes' => pack('V', 1_800_000)],
                    8 => ['base_type' => 0x86, 'bytes' => pack('V', 1_700_000)],
                ] + $fields,
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(SessionItem::class, $items[0]);

        $reading = $this->reading(
            $items[0]->session->readings(),
            $measurementType,
        );

        self::assertInstanceOf(
            ScalarMeasurement::class,
            $reading->measurement,
        );
        self::assertSame($value, $reading->measurement->value);
        self::assertSame(
            $unit,
            $reading->measurement->unit->toString(),
        );
    }

    /**
     * @param list<MeasurementReading> $readings
     */
    private function reading(
        array $readings,
        string $type,
    ): MeasurementReading {
        foreach ($readings as $reading) {
            if (
                $type
                === $reading->measurement->type()->toString()
            ) {
                return $reading;
            }
        }

        self::fail(
            sprintf('Reading %s was not found.', $type),
        );
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

        return FitDecoder::standard(TestFitProfile::load())->decode(
            RawDataMessage::create(
                sequenceNumber: 1,
                byteOffset: 20,
                recordHeaderByte: 0x00,
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

    private function fitTimestamp(string $value): int
    {
        return (new \DateTimeImmutable($value))->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }

    private function semicircles(float $degrees): int
    {
        return (int) round(
            $degrees * 2_147_483_648.0 / 180.0,
        );
    }

    private function sint32(int $value): string
    {
        return pack('V', $value & 0xFFFFFFFF);
    }
}
