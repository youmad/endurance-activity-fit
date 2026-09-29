<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityFit\Mapper\FitRecordMessageMapper;
use Youmad\Endurance\ActivityFit\Record\FitRecordMeasurementRegistry;
use Youmad\Endurance\ActivityFit\Record\FitRecordScalarMeasurementDefinition;
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

final class FitRecordMeasurementExpansionTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testMapsRepresentativeRunningCyclingPhysiologyAndDivingMetrics(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                $this->timestampProfile(),
                FieldProfile::create(
                    fieldNumber: 4,
                    name: 'cadence',
                    typeName: 'uint8',
                    units: 'rpm',
                ),
                FieldProfile::create(
                    fieldNumber: 52,
                    name: 'cadence256',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 256,
                    ),
                    units: 'rpm',
                ),
                FieldProfile::create(
                    fieldNumber: 39,
                    name: 'vertical_oscillation',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 10,
                    ),
                    units: 'mm',
                ),
                FieldProfile::create(
                    fieldNumber: 43,
                    name: 'left_torque_effectiveness',
                    typeName: 'uint8',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 2,
                    ),
                    units: 'percent',
                ),
                FieldProfile::create(
                    fieldNumber: 108,
                    name: 'enhanced_respiration_rate',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 100,
                    ),
                    units: 'Breaths/min',
                ),
                FieldProfile::create(
                    fieldNumber: 91,
                    name: 'absolute_pressure',
                    typeName: 'uint32',
                    units: 'Pa',
                ),
                FieldProfile::create(
                    fieldNumber: 118,
                    name: 'ebike_battery_level',
                    typeName: 'uint8',
                    units: 'percent',
                ),
                FieldProfile::create(
                    fieldNumber: 92,
                    name: 'depth',
                    typeName: 'uint32',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 1000,
                    ),
                    units: 'm',
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
                    4 => [
                        'base_type' => 0x02,
                        'bytes' => chr(90),
                    ],
                    52 => [
                        'base_type' => 0x84,
                        'bytes' => pack(
                            'v',
                            (int) (90.5 * 256),
                        ),
                    ],
                    39 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 95),
                    ],
                    43 => [
                        'base_type' => 0x02,
                        'bytes' => chr(170),
                    ],
                    108 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 1825),
                    ],
                    91 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 101_325),
                    ],
                    118 => [
                        'base_type' => 0x02,
                        'bytes' => chr(72),
                    ],
                    92 => [
                        'base_type' => 0x86,
                        'bytes' => pack('V', 12_500),
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

        $observation = $item->observation;

        $this->assertScalar(
            observation: $observation,
            type: 'cadence',
            value: 90.5,
            unit: 'rpm',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'vertical_oscillation',
            value: 9.5,
            unit: 'mm',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'left_torque_effectiveness',
            value: 85,
            unit: '%',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'respiration_rate',
            value: 18.25,
            unit: 'breaths/min',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'absolute_pressure',
            value: 101_325,
            unit: 'Pa',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'ebike_battery_level',
            value: 72,
            unit: '%',
        );

        $this->assertScalar(
            observation: $observation,
            type: 'depth',
            value: 12.5,
            unit: 'm',
        );
    }

    public function testCombinesCadenceWithFractionalCadenceWhenCadence256IsUnavailable(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                $this->timestampProfile(),
                FieldProfile::create(
                    fieldNumber: 4,
                    name: 'cadence',
                    typeName: 'uint8',
                    units: 'rpm',
                ),
                FieldProfile::create(
                    fieldNumber: 53,
                    name: 'fractional_cadence',
                    typeName: 'uint8',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 128,
                    ),
                    units: 'rpm',
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
                    4 => [
                        'base_type' => 0x02,
                        'bytes' => chr(87),
                    ],
                    53 => [
                        'base_type' => 0x02,
                        'bytes' => chr(64),
                    ],
                ],
            ),
        );

        self::assertCount(1, $items);

        self::assertInstanceOf(ObservationItem::class, $items[0]);
        $this->assertScalar(
            observation: $items[0]->observation,
            type: 'cadence',
            value: 87.5,
            unit: 'rpm',
        );
    }

    public function testCadence256TakesPriorityOverLegacyCadencePair(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                $this->timestampProfile(),
                FieldProfile::create(
                    fieldNumber: 4,
                    name: 'cadence',
                    typeName: 'uint8',
                    units: 'rpm',
                ),
                FieldProfile::create(
                    fieldNumber: 52,
                    name: 'cadence256',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 256,
                    ),
                    units: 'rpm',
                ),
                FieldProfile::create(
                    fieldNumber: 53,
                    name: 'fractional_cadence',
                    typeName: 'uint8',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 128,
                    ),
                    units: 'rpm',
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
                    4 => [
                        'base_type' => 0x02,
                        'bytes' => chr(87),
                    ],
                    52 => [
                        'base_type' => 0x84,
                        'bytes' => pack(
                            'v',
                            (int) (88.25 * 256),
                        ),
                    ],
                    53 => [
                        'base_type' => 0x02,
                        'bytes' => chr(64),
                    ],
                ],
            ),
        );

        self::assertCount(1, $items);

        self::assertInstanceOf(ObservationItem::class, $items[0]);
        $this->assertScalar(
            observation: $items[0]->observation,
            type: 'cadence',
            value: 88.25,
            unit: 'rpm',
        );
    }

    public function testDoesNotTreatLegacyRespirationSecondsAsBreathsPerMinute(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                $this->timestampProfile(),
                FieldProfile::create(
                    fieldNumber: 3,
                    name: 'heart_rate',
                    typeName: 'uint8',
                    units: 'bpm',
                ),
                FieldProfile::create(
                    fieldNumber: 99,
                    name: 'respiration_rate',
                    typeName: 'uint8',
                    units: 's',
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
                    3 => [
                        'base_type' => 0x02,
                        'bytes' => chr(150),
                    ],
                    99 => [
                        'base_type' => 0x02,
                        'bytes' => chr(3),
                    ],
                ],
            ),
        );

        self::assertCount(1, $items);

        self::assertInstanceOf(ObservationItem::class, $items[0]);
        $observation = $items[0]->observation;

        $this->assertScalar(
            observation: $observation,
            type: 'heart_rate',
            value: 150,
            unit: 'bpm',
        );

        self::assertCount(
            1,
            $observation->readings(),
        );
    }

    public function testCustomRegistryExtendsMapperWithoutChangingIt(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                $this->timestampProfile(),
                FieldProfile::create(
                    fieldNumber: 200,
                    name: 'custom_field',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 10,
                    ),
                    units: 'custom-unit',
                ),
            ],
        );

        $registry = new FitRecordMeasurementRegistry(
            scalarDefinitions: [
                FitRecordScalarMeasurementDefinition::create(
                    measurementType: 'custom_metric',
                    fieldNames: ['custom_field'],
                    unit: 'custom-unit',
                ),
            ],
        );

        $items = (new FitRecordMessageMapper(
            measurements: $registry,
        ))->map(
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
                    200 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 425),
                    ],
                ],
            ),
        );

        self::assertCount(1, $items);

        self::assertInstanceOf(ObservationItem::class, $items[0]);
        $this->assertScalar(
            observation: $items[0]->observation,
            type: 'custom_metric',
            value: 42.5,
            unit: 'custom-unit',
        );

        self::assertCount(
            1,
            $items[0]->observation->readings(),
        );
    }

    private function timestampProfile(): FieldProfile
    {
        return FieldProfile::create(
            fieldNumber: 253,
            name: 'timestamp',
            typeName: 'date_time',
            units: 's',
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
}
