<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\ValueObject\SummaryAdjacencyPolicy;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitLapMessageMapper;
use Youmad\Endurance\ActivityFit\Tests\Fixture\TestFitProfile;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Profile\FieldProfile;
use Youmad\Endurance\Fit\Profile\FieldTransform;
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
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final class FitLapMessageMapperTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testMapsLapSummaryToLapItemWithMillisecondPrecision(): void
    {
        $items = (new FitLapMessageMapper())->map(
            $this->message(
                fields: [
                    254 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 0x8002),
                    ],
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:45:01Z',
                            ),
                        ),
                    ],
                    2 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:00Z',
                            ),
                        ),
                    ],
                    7 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 900_500),
                    ],
                    8 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 850_250),
                    ],
                    9 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 1_234_500),
                    ],
                    15 => [
                        'base_type' => 0x02,
                        'bytes' => chr(150),
                    ],
                    19 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 245),
                    ],
                    32 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 4),
                    ],
                    35 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 7),
                    ],
                    40 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 3),
                    ],
                    110 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 8_510),
                    ],
                ],
            ),
        );

        self::assertCount(1, $items);

        $item = $items[0];

        self::assertInstanceOf(
            LapItem::class,
            $item,
        );
        self::assertSame(2, $item->index);
        self::assertSame(7, $item->firstLengthIndex);
        self::assertSame(4, $item->lengthCount);
        self::assertSame(3, $item->activeLengthCount);

        self::assertSame(
            '2026-01-15T10:30:00.000000+00:00',
            $item->lap
                ->startedAt
                ->toDateTimeImmutable()
                ->format('Y-m-d\TH:i:s.uP'),
        );

        self::assertSame(
            '2026-01-15T10:45:00.500000+00:00',
            $item->lap
                ->finishedAt
                ->toDateTimeImmutable()
                ->format('Y-m-d\TH:i:s.uP'),
        );

        self::assertSame(
            900_500_000,
            $item->lap
                ->elapsedDuration()
                ->toMicroseconds(),
        );

        self::assertSame(
            850_250_000,
            $item->lap
                ->timerDuration
                ->toMicroseconds(),
        );

        self::assertSame(
            TemporalResolution::Second,
            $item->lap->timelineResolution,
        );

        $distance = $this->reading($item->lap->readings(), 'total_distance');
        self::assertInstanceOf(ScalarMeasurement::class, $distance->measurement);
        self::assertSame(12_345, $distance->measurement->value);

        $heartRate = $this->reading(
            $item->lap->readings(),
            'average_heart_rate',
        );
        self::assertInstanceOf(ScalarMeasurement::class, $heartRate->measurement);
        self::assertSame(150, $heartRate->measurement->value);

        $power = $this->reading($item->lap->readings(), 'average_power');
        self::assertInstanceOf(ScalarMeasurement::class, $power->measurement);
        self::assertSame(245, $power->measurement->value);

        $speed = $this->reading($item->lap->readings(), 'average_speed');
        self::assertInstanceOf(ScalarMeasurement::class, $speed->measurement);
        self::assertSame(8.51, $speed->measurement->value);
    }

    public function testPreservesProfileSelectedLapSubfieldMeasurementSemantics(): void
    {
        $this->assertLapScalarMeasurement(
            fields: [
                25 => ['base_type' => 0x00, 'bytes' => "\x01"],
                10 => ['base_type' => 0x86, 'bytes' => pack('V', 12_345)],
            ],
            measurementType: 'total_strides',
            value: 12_345,
            unit: 'strides',
        );
        $this->assertLapScalarMeasurement(
            fields: [
                25 => ['base_type' => 0x00, 'bytes' => "\x05"],
                10 => ['base_type' => 0x86, 'bytes' => pack('V', 4_321)],
            ],
            measurementType: 'total_strokes',
            value: 4_321,
            unit: 'strokes',
        );
        $this->assertLapScalarMeasurement(
            fields: [
                10 => ['base_type' => 0x86, 'bytes' => pack('V', 321)],
            ],
            measurementType: 'total_cycles',
            value: 321,
            unit: 'cycles',
        );
        $this->assertLapScalarMeasurement(
            fields: [
                25 => ['base_type' => 0x00, 'bytes' => "\x01"],
                17 => ['base_type' => 0x02, 'bytes' => chr(172)],
            ],
            measurementType: 'average_running_cadence',
            value: 172,
            unit: 'strides/min',
        );
        $this->assertLapScalarMeasurement(
            fields: [
                25 => ['base_type' => 0x00, 'bytes' => "\x01"],
                18 => ['base_type' => 0x02, 'bytes' => chr(188)],
            ],
            measurementType: 'maximum_running_cadence',
            value: 188,
            unit: 'strides/min',
        );
        $this->assertLapScalarMeasurement(
            fields: [
                17 => ['base_type' => 0x02, 'bytes' => chr(92)],
            ],
            measurementType: 'average_cadence',
            value: 92,
            unit: 'rpm',
        );
        $this->assertLapScalarMeasurement(
            fields: [
                18 => ['base_type' => 0x02, 'bytes' => chr(108)],
            ],
            measurementType: 'maximum_cadence',
            value: 108,
            unit: 'rpm',
        );
    }

    public function testStandardMapperIncludesLapMapper(): void
    {
        $items = FitActivityImportItemMapper::standard()->map(
            $this->validMessage(),
        );

        self::assertCount(1, $items);

        self::assertInstanceOf(
            LapItem::class,
            $items[0],
        );
    }

    public function testRejectsLapWithoutRequiredField(): void
    {
        $this->expectException(
            InvalidFitActivityMessage::class,
        );

        $this->expectExceptionMessage(
            'has no usable total_timer_time field',
        );

        (new FitLapMessageMapper())->map(
            $this->message(
                fields: [
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:45:00Z',
                            ),
                        ),
                    ],
                    2 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:00Z',
                            ),
                        ),
                    ],
                    7 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 900_000),
                    ],
                ],
            ),
        );
    }

    public function testPreservesReportedElapsedDurationWithLongerTimer(): void
    {
        $items = (new FitLapMessageMapper())->map(
            $this->message(
                fields: [
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:45:00Z',
                            ),
                        ),
                    ],
                    2 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:00Z',
                            ),
                        ),
                    ],
                    7 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 900_000),
                    ],
                    8 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 900_590),
                    ],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(LapItem::class, $items[0]);
        self::assertSame(
            900_000_000,
            $items[0]->lap->elapsedDuration()->toMicroseconds(),
        );
        self::assertSame(
            900_590_000,
            $items[0]->lap->timerDuration->toMicroseconds(),
        );
        self::assertSame(
            '2026-01-15T10:45:00.000000+00:00',
            $items[0]->lap
                ->finishedAt
                ->toDateTimeImmutable()
                ->format('Y-m-d\TH:i:s.uP'),
        );
    }

    public function testReportsLongerTimerWithoutAdjustingElapsedDuration(): void
    {
        $mapper = FitActivityImportItemMapper::standard();

        $mapper->map(
            $this->message(
                fields: [
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:39:19Z',
                            ),
                        ),
                    ],
                    2 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:00Z',
                            ),
                        ),
                    ],
                    7 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 559_000),
                    ],
                    8 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 559_590),
                    ],
                ],
            ),
        );

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::LapTimerDurationExceedsElapsed,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'messageName' => 'lap',
                'sequence' => 1,
                'byteOffset' => 20,
                'elapsedMicroseconds' => 559_000_000,
                'timerMicroseconds' => 559_590_000,
                'excessMicroseconds' => 590_000,
            ],
            $warnings[0]->context,
        );
    }

    #[DataProvider('timerExcesses')]
    public function testPreservesTimerExcessBeyondTimestampResolution(int $excessMilliseconds): void
    {
        $items = (new FitLapMessageMapper())->map(
            $this->message(
                fields: [
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:45:00Z',
                            ),
                        ),
                    ],
                    2 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:00Z',
                            ),
                        ),
                    ],
                    7 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 900_000),
                    ],
                    8 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 900_000 + $excessMilliseconds),
                    ],
                ],
            ),
        );

        self::assertInstanceOf(LapItem::class, $items[0]);
        self::assertSame(900_000_000, $items[0]->lap->elapsedDuration()->toMicroseconds());
        self::assertSame(
            (900_000 + $excessMilliseconds) * 1000,
            $items[0]->lap->timerDuration->toMicroseconds(),
        );
        self::assertSame(
            '2026-01-15T10:45:00+00:00',
            $items[0]->lap->finishedAt->toDateTimeImmutable()->format('c'),
        );
    }

    /** @return iterable<string, array{int}> */
    public static function timerExcesses(): iterable
    {
        yield 'one second' => [1000];
        yield 'one second plus one millisecond' => [1001];
        yield 'two seconds' => [2000];
        yield 'no invented upper tolerance' => [5000];
    }

    public function testUsesStartTimeAndElapsedDurationForSummaryFirstFiles(): void
    {
        $items = (new FitLapMessageMapper())->map(
            $this->message(
                fields: [
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T12:00:00Z',
                            ),
                        ),
                    ],
                    2 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:00Z',
                            ),
                        ),
                    ],
                    7 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 900_500),
                    ],
                    8 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 850_000),
                    ],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(LapItem::class, $items[0]);

        self::assertSame(
            '2026-01-15T10:45:00.500000+00:00',
            $items[0]->lap
                ->finishedAt
                ->toDateTimeImmutable()
                ->format('Y-m-d\TH:i:s.uP'),
        );
    }

    /**
     * @param array<int, array{base_type: int, bytes: string}> $fields
     */
    private function assertLapScalarMeasurement(
        array $fields,
        string $measurementType,
        int|float $value,
        string $unit,
    ): void {
        $items = (new FitLapMessageMapper())->map(
            $this->standardProfileMessage(
                [
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:45:00Z',
                            ),
                        ),
                    ],
                    2 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:00Z',
                            ),
                        ),
                    ],
                    7 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 900_000),
                    ],
                    8 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 850_000),
                    ],
                ] + $fields,
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(LapItem::class, $items[0]);
        self::assertSame(SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds, $items[0]->lap->adjacencyPolicy);
        self::assertCount(1, $items[0]->lap->readings());

        $reading = $this->reading(
            $items[0]->lap->readings(),
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

    private function validMessage(): UnifiedDataMessage
    {
        return $this->message(
            fields: [
                253 => [
                    'base_type' => 0x86,
                    'bytes' => pack(
                        'V',
                        $this->fitTimestamp(
                            '2026-01-15T10:45:00Z',
                        ),
                    ),
                ],
                2 => [
                    'base_type' => 0x86,
                    'bytes' => pack(
                        'V',
                        $this->fitTimestamp(
                            '2026-01-15T10:30:00Z',
                        ),
                    ),
                ],
                7 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', 900_000),
                ],
                8 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', 850_000),
                ],
            ],
        );
    }

    private function profile(): MessageProfile
    {
        return MessageProfile::create(
            globalMessageNumber: 19,
            name: 'lap',
            fields: [
                FieldProfile::create(
                    fieldNumber: 254,
                    name: 'message_index',
                    typeName: 'message_index',
                ),
                FieldProfile::create(
                    fieldNumber: 253,
                    name: 'timestamp',
                    typeName: 'date_time',
                    units: 's',
                ),
                FieldProfile::create(
                    fieldNumber: 2,
                    name: 'start_time',
                    typeName: 'date_time',
                ),
                FieldProfile::create(
                    fieldNumber: 7,
                    name: 'total_elapsed_time',
                    typeName: 'uint32',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 1000,
                    ),
                    units: 's',
                ),
                FieldProfile::create(
                    fieldNumber: 8,
                    name: 'total_timer_time',
                    typeName: 'uint32',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 1000,
                    ),
                    units: 's',
                ),
                FieldProfile::create(
                    fieldNumber: 9,
                    name: 'total_distance',
                    typeName: 'uint32',
                    transform: FieldTransform::scaleAndOffset(scale: 100),
                    units: 'm',
                ),
                FieldProfile::create(
                    fieldNumber: 15,
                    name: 'avg_heart_rate',
                    typeName: 'uint8',
                    units: 'bpm',
                ),
                FieldProfile::create(
                    fieldNumber: 19,
                    name: 'avg_power',
                    typeName: 'uint16',
                    units: 'watts',
                ),
                FieldProfile::create(
                    fieldNumber: 32,
                    name: 'num_lengths',
                    typeName: 'uint16',
                    units: 'lengths',
                ),
                FieldProfile::create(
                    fieldNumber: 35,
                    name: 'first_length_index',
                    typeName: 'uint16',
                ),
                FieldProfile::create(
                    fieldNumber: 40,
                    name: 'num_active_lengths',
                    typeName: 'uint16',
                    units: 'lengths',
                ),
                FieldProfile::create(
                    fieldNumber: 110,
                    name: 'enhanced_avg_speed',
                    typeName: 'uint32',
                    transform: FieldTransform::scaleAndOffset(scale: 1000),
                    units: 'm/s',
                ),
            ],
        );
    }

    /**
     * @param array<
     *     int,
     *     array{
     *         base_type: int,
     *         bytes: string
     *     }
     * > $fields
     */
    private function message(
        array $fields,
    ): UnifiedDataMessage {
        $profile = $this->profile();
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
                    $profile,
                ),
                types: new InMemoryFitTypeRegistry(),
            )
        )->decode($raw);
    }

    /**
     * @param array<int, array{base_type: int, bytes: string}> $fields
     */
    private function standardProfileMessage(
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

        return FitDecoder::standard(TestFitProfile::load())->decode(
            RawDataMessage::create(
                sequenceNumber: 1,
                byteOffset: 20,
                recordHeaderByte: 0x00,
                definition: MessageDefinition::create(
                    localMessageNumber: 0,
                    architecture: FitArchitecture::LittleEndian,
                    globalMessageNumber: 19,
                    standardFields: $definitions,
                ),
                standardFields: $rawFields,
            ),
        );
    }

    /** @param list<MeasurementReading> $readings */
    private function reading(array $readings, string $type): MeasurementReading
    {
        foreach ($readings as $reading) {
            if ($type === $reading->measurement->type()->toString()) {
                return $reading;
            }
        }

        self::fail(sprintf('Reading %s was not found.', $type));
    }

    private function fitTimestamp(string $value): int
    {
        return (
            new \DateTimeImmutable($value)
        )->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }
}
