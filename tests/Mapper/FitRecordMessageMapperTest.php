<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementOrigin;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\PositionMeasurement;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitRecordMessageMapper;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Profile\ComponentProfile;
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

final class FitRecordMessageMapperTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testMapsRecordToObservationUsingEnhancedValues(): void
    {
        $profile = $this->completeRecordProfile();

        $message = $this->message(
            profile: $profile,
            fields: [
                253 => [
                    'base_type' => 0x86,
                    'bytes' => pack(
                        'V',
                        $this->fitTimestamp(
                            '2026-01-15T10:30:01Z',
                        ),
                    ),
                ],
                0 => [
                    'base_type' => 0x85,
                    'bytes' => $this->sint32(
                        $this->semicircles(59.4369),
                    ),
                ],
                1 => [
                    'base_type' => 0x85,
                    'bytes' => $this->sint32(
                        $this->semicircles(24.7535),
                    ),
                ],
                2 => [
                    'base_type' => 0x84,
                    'bytes' => pack('v', 2750),
                ],
                78 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', 2762),
                ],
                3 => [
                    'base_type' => 0x02,
                    'bytes' => chr(150),
                ],
                4 => [
                    'base_type' => 0x02,
                    'bytes' => chr(90),
                ],
                5 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', 12_345),
                ],
                6 => [
                    'base_type' => 0x84,
                    'bytes' => pack('v', 7000),
                ],
                73 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', 8510),
                ],
                7 => [
                    'base_type' => 0x84,
                    'bytes' => pack('v', 250),
                ],
                9 => [
                    'base_type' => 0x83,
                    'bytes' => pack('v', 350),
                ],
                13 => [
                    'base_type' => 0x01,
                    'bytes' => chr(20),
                ],
            ],
        );

        $items = (new FitRecordMessageMapper())
            ->map($message);

        self::assertCount(1, $items);

        $item = $items[0];

        self::assertInstanceOf(
            ObservationItem::class,
            $item,
        );

        $observation = $item->observation;

        self::assertSame(
            '2026-01-15T10:30:01+00:00',
            $observation
                ->timestamp
                ->toDateTimeImmutable()
                ->format(DATE_ATOM),
        );

        $position = $this->reading(
            observation: $observation,
            measurementType: 'position',
        );

        self::assertSame(
            MeasurementOrigin::Reported,
            $position->origin,
        );

        self::assertInstanceOf(
            PositionMeasurement::class,
            $position->measurement,
        );

        self::assertEqualsWithDelta(
            59.4369,
            $position->measurement
                ->coordinate
                ->latitude,
            0.0000001,
        );

        self::assertEqualsWithDelta(
            24.7535,
            $position->measurement
                ->coordinate
                ->longitude,
            0.0000001,
        );

        $this->assertScalar(
            observation: $observation,
            type: 'altitude',
            value: 52.4,
            unit: 'm',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'heart_rate',
            value: 150,
            unit: 'bpm',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'cadence',
            value: 90,
            unit: 'rpm',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'distance',
            value: 123.45,
            unit: 'm',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'speed',
            value: 8.51,
            unit: 'm/s',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'power',
            value: 250,
            unit: 'W',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'grade',
            value: 3.5,
            unit: '%',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'temperature',
            value: 20,
            unit: '°C',
        );
    }

    public function testMapsComponentDerivedSpeedAsDerivedReading(): void
    {
        $profile = MessageProfile::create(
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
                    fieldNumber: 6,
                    name: 'speed',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 1000,
                    ),
                    units: 'm/s',
                ),
                FieldProfile::create(
                    fieldNumber: 8,
                    name: 'compressed_speed',
                    typeName: 'uint16',
                    components: [
                        ComponentProfile::create(
                            targetFieldNumber: 6,
                            bits: 12,
                            transform: FieldTransform::scaleAndOffset(
                                scale: 100,
                            ),
                            units: 'm/s',
                        ),
                    ],
                ),
            ],
        );

        $items = (new FitRecordMessageMapper())->map(
            $this->message(
                profile: $profile,
                fields: [
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:01Z',
                            ),
                        ),
                    ],
                    8 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 850),
                    ],
                ],
            ),
        );

        self::assertCount(1, $items);

        $item = $items[0];

        self::assertInstanceOf(
            ObservationItem::class,
            $item,
        );

        $speed = $this->reading(
            observation: $item->observation,
            measurementType: 'speed',
        );

        self::assertSame(
            MeasurementOrigin::Derived,
            $speed->origin,
        );

        self::assertInstanceOf(
            ScalarMeasurement::class,
            $speed->measurement,
        );

        self::assertSame(
            8.5,
            $speed->measurement->value,
        );
    }

    public function testUsesFirstComponentDerivedEnhancedSpeedWhenMultipleValuesExist(): void
    {
        $profile = MessageProfile::create(
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
                    fieldNumber: 6,
                    name: 'speed',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 1000,
                    ),
                    units: 'm/s',
                    components: [
                        ComponentProfile::create(
                            targetFieldNumber: 73,
                            bits: 16,
                            transform: FieldTransform::scaleAndOffset(
                                scale: 1000,
                            ),
                            units: 'm/s',
                        ),
                    ],
                ),
                FieldProfile::create(
                    fieldNumber: 8,
                    name: 'compressed_speed_distance',
                    typeName: 'uint16',
                    components: [
                        ComponentProfile::create(
                            targetFieldNumber: 6,
                            bits: 12,
                            transform: FieldTransform::scaleAndOffset(
                                scale: 100,
                            ),
                            units: 'm/s',
                        ),
                    ],
                ),
                FieldProfile::create(
                    fieldNumber: 73,
                    name: 'enhanced_speed',
                    typeName: 'uint32',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 1000,
                    ),
                    units: 'm/s',
                ),
            ],
        );

        $items = (new FitRecordMessageMapper())->map(
            $this->message(
                profile: $profile,
                fields: [
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:01Z',
                            ),
                        ),
                    ],
                    6 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 7200),
                    ],
                    8 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 850),
                    ],
                ],
            ),
        );

        self::assertCount(1, $items);

        $item = $items[0];

        self::assertInstanceOf(
            ObservationItem::class,
            $item,
        );

        $speed = $this->reading(
            observation: $item->observation,
            measurementType: 'speed',
        );

        self::assertSame(
            MeasurementOrigin::Derived,
            $speed->origin,
        );

        self::assertInstanceOf(
            ScalarMeasurement::class,
            $speed->measurement,
        );

        self::assertSame(
            7.2,
            $speed->measurement->value,
        );
    }

    public function testFallsBackFromInvalidEnhancedSpeedToLegacySpeed(): void
    {
        $profile = MessageProfile::create(
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
                    fieldNumber: 6,
                    name: 'speed',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 1000,
                    ),
                    units: 'm/s',
                ),
                FieldProfile::create(
                    fieldNumber: 73,
                    name: 'enhanced_speed',
                    typeName: 'uint32',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 1000,
                    ),
                    units: 'm/s',
                ),
            ],
        );

        $items = (new FitRecordMessageMapper())->map(
            $this->message(
                profile: $profile,
                fields: [
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:01Z',
                            ),
                        ),
                    ],
                    6 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 7000),
                    ],
                    73 => [
                        'base_type' => 0x86,
                        'bytes' => "\xFF\xFF\xFF\xFF",
                    ],
                ],
            ),
        );

        self::assertCount(1, $items);

        $item = $items[0];

        self::assertInstanceOf(
            ObservationItem::class,
            $item,
        );

        $speed = $this->reading(
            observation: $item->observation,
            measurementType: 'speed',
        );

        self::assertSame(
            MeasurementOrigin::Reported,
            $speed->origin,
        );

        self::assertInstanceOf(
            ScalarMeasurement::class,
            $speed->measurement,
        );

        self::assertSame(
            7,
            $speed->measurement->value,
        );
    }

    public function testSkipsPartialRecordPositionAndPreservesOtherReadings(): void
    {
        $message = $this->message(
            profile: $this->completeRecordProfile(),
            fields: [
                253 => [
                    'base_type' => 0x86,
                    'bytes' => pack(
                        'V',
                        $this->fitTimestamp(
                            '2026-01-15T10:30:01Z',
                        ),
                    ),
                ],
                0 => [
                    'base_type' => 0x85,
                    'bytes' => $this->sint32(
                        $this->semicircles(59.4369),
                    ),
                ],
                3 => [
                    'base_type' => 0x02,
                    'bytes' => chr(150),
                ],
            ],
        );

        $mapper = FitActivityImportItemMapper::standard();
        $items = $mapper->map($message);

        self::assertCount(1, $items);
        self::assertInstanceOf(ObservationItem::class, $items[0]);

        $observation = $items[0]->observation;

        self::assertFalse(
            $this->hasReading(
                observation: $observation,
                measurementType: 'position',
            ),
        );
        $this->assertScalar(
            observation: $observation,
            type: 'heart_rate',
            value: 150,
            unit: 'bpm',
        );

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::RecordCoordinatePairSkipped,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'sequence' => 1,
                'byteOffset' => 20,
                'latitudeField' => 'position_lat',
                'longitudeField' => 'position_long',
                'resolvedField' => 'position_lat',
                'unresolvedField' => 'position_long',
            ],
            $warnings[0]->context,
        );
    }

    public function testTreatsInvalidRecordCoordinateAsUnresolved(): void
    {
        $message = $this->message(
            profile: $this->completeRecordProfile(),
            fields: [
                253 => [
                    'base_type' => 0x86,
                    'bytes' => pack(
                        'V',
                        $this->fitTimestamp(
                            '2026-01-15T10:30:01Z',
                        ),
                    ),
                ],
                0 => [
                    'base_type' => 0x85,
                    'bytes' => $this->sint32(
                        $this->semicircles(59.4369),
                    ),
                ],
                1 => [
                    'base_type' => 0x85,
                    'bytes' => pack('V', 0x7FFFFFFF),
                ],
                3 => [
                    'base_type' => 0x02,
                    'bytes' => chr(150),
                ],
            ],
        );

        $mapper = FitActivityImportItemMapper::standard();
        $items = $mapper->map($message);

        self::assertCount(1, $items);
        self::assertInstanceOf(ObservationItem::class, $items[0]);
        self::assertFalse(
            $this->hasReading(
                observation: $items[0]->observation,
                measurementType: 'position',
            ),
        );

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::RecordCoordinatePairSkipped,
            $warnings[0]->code,
        );
        self::assertSame(
            'position_long',
            $warnings[0]->context['unresolvedField'],
        );
    }

    public function testRebuildsFieldLookupIndexForEachRecordMessage(): void
    {
        $profile = MessageProfile::create(
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

        $mapper = new FitRecordMessageMapper();

        $first = $mapper->map(
            $this->message(
                profile: $profile,
                fields: [
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:01Z',
                            ),
                        ),
                    ],
                    3 => [
                        'base_type' => 0x02,
                        'bytes' => chr(150),
                    ],
                ],
            ),
        );

        $second = $mapper->map(
            $this->message(
                profile: $profile,
                fields: [
                    253 => [
                        'base_type' => 0x86,
                        'bytes' => pack(
                            'V',
                            $this->fitTimestamp(
                                '2026-01-15T10:30:02Z',
                            ),
                        ),
                    ],
                    3 => [
                        'base_type' => 0x02,
                        'bytes' => chr(151),
                    ],
                ],
            ),
        );

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertInstanceOf(ObservationItem::class, $first[0]);
        self::assertInstanceOf(ObservationItem::class, $second[0]);

        $this->assertScalar(
            observation: $first[0]->observation,
            type: 'heart_rate',
            value: 150,
            unit: 'bpm',
        );
        $this->assertScalar(
            observation: $second[0]->observation,
            type: 'heart_rate',
            value: 151,
            unit: 'bpm',
        );
    }

    public function testSkipsRecordWithoutTimestamp(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                FieldProfile::create(
                    fieldNumber: 3,
                    name: 'heart_rate',
                    typeName: 'uint8',
                    units: 'bpm',
                ),
            ],
        );

        self::assertSame(
            [],
            (new FitRecordMessageMapper())->map(
                $this->message(
                    profile: $profile,
                    fields: [
                        3 => [
                            'base_type' => 0x02,
                            'bytes' => chr(150),
                        ],
                    ],
                ),
            ),
        );
    }

    public function testTimestampOnlyRecordProducesNoImportItem(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                FieldProfile::create(
                    fieldNumber: 253,
                    name: 'timestamp',
                    typeName: 'date_time',
                    units: 's',
                ),
            ],
        );

        self::assertSame(
            [],
            (new FitRecordMessageMapper())->map(
                $this->message(
                    profile: $profile,
                    fields: [
                        253 => [
                            'base_type' => 0x86,
                            'bytes' => pack(
                                'V',
                                $this->fitTimestamp(
                                    '2026-01-15T10:30:01Z',
                                ),
                            ),
                        ],
                    ],
                ),
            ),
        );
    }

    private function completeRecordProfile(): MessageProfile
    {
        return MessageProfile::create(
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
                    fieldNumber: 0,
                    name: 'position_lat',
                    typeName: 'sint32',
                    units: 'semicircles',
                ),
                FieldProfile::create(
                    fieldNumber: 1,
                    name: 'position_long',
                    typeName: 'sint32',
                    units: 'semicircles',
                ),
                FieldProfile::create(
                    fieldNumber: 2,
                    name: 'altitude',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 5,
                        offset: 500,
                    ),
                    units: 'm',
                ),
                FieldProfile::create(
                    fieldNumber: 78,
                    name: 'enhanced_altitude',
                    typeName: 'uint32',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 5,
                        offset: 500,
                    ),
                    units: 'm',
                ),
                FieldProfile::create(
                    fieldNumber: 3,
                    name: 'heart_rate',
                    typeName: 'uint8',
                    units: 'bpm',
                ),
                FieldProfile::create(
                    fieldNumber: 4,
                    name: 'cadence',
                    typeName: 'uint8',
                    units: 'rpm',
                ),
                FieldProfile::create(
                    fieldNumber: 5,
                    name: 'distance',
                    typeName: 'uint32',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 100,
                    ),
                    units: 'm',
                    accumulated: true,
                ),
                FieldProfile::create(
                    fieldNumber: 6,
                    name: 'speed',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 1000,
                    ),
                    units: 'm/s',
                ),
                FieldProfile::create(
                    fieldNumber: 73,
                    name: 'enhanced_speed',
                    typeName: 'uint32',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 1000,
                    ),
                    units: 'm/s',
                ),
                FieldProfile::create(
                    fieldNumber: 7,
                    name: 'power',
                    typeName: 'uint16',
                    units: 'watts',
                ),
                FieldProfile::create(
                    fieldNumber: 9,
                    name: 'grade',
                    typeName: 'sint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 100,
                    ),
                    units: '%',
                ),
                FieldProfile::create(
                    fieldNumber: 13,
                    name: 'temperature',
                    typeName: 'sint8',
                    units: 'C',
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
        MessageProfile $profile,
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
                    $profile,
                ),
                types: new InMemoryFitTypeRegistry(),
            )
        )->decode($raw);
    }

    private function fitTimestamp(string $value): int
    {
        return (
            new \DateTimeImmutable($value)
        )->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }

    private function semicircles(float $degrees): int
    {
        return (int) round(
            $degrees
            * 2_147_483_648.0
            / 180.0,
        );
    }

    private function sint32(int $value): string
    {
        return pack(
            'V',
            $value & 0xFFFFFFFF,
        );
    }

    private function hasReading(
        ActivityObservation $observation,
        string $measurementType,
    ): bool {
        foreach ($observation->readings() as $reading) {
            if (
                $measurementType
                === $reading->measurement->type()->toString()
            ) {
                return true;
            }
        }

        return false;
    }

    private function reading(
        ActivityObservation $observation,
        string $measurementType,
    ): MeasurementReading {
        foreach ($observation->readings() as $reading) {
            if (
                $measurementType
                === $reading
                    ->measurement
                    ->type()
                    ->toString()
            ) {
                return $reading;
            }
        }

        self::fail(
            sprintf(
                'Measurement %s was not found.',
                $measurementType,
            ),
        );
    }

    private function assertScalar(
        ActivityObservation $observation,
        string $type,
        int|float $value,
        string $unit,
    ): void {
        $reading = $this->reading(
            observation: $observation,
            measurementType: $type,
        );

        self::assertSame(
            MeasurementOrigin::Reported,
            $reading->origin,
        );

        self::assertInstanceOf(
            ScalarMeasurement::class,
            $reading->measurement,
        );

        if (is_float($value)) {
            self::assertEqualsWithDelta(
                $value,
                $reading->measurement->value,
                0.000000001,
            );
        } else {
            self::assertSame(
                $value,
                $reading->measurement->value,
            );
        }

        self::assertSame(
            $unit,
            $reading
                ->measurement
                ->unit
                ->toString(),
        );
    }
}
