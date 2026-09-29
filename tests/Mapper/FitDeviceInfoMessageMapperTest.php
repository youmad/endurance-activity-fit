<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\DeviceItem;
use Youmad\Endurance\Activity\Application\Import\DeviceStatusItem;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\Telemetry\TextMeasurement;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitDeviceInfoMessageMapper;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Profile\FieldProfile;
use Youmad\Endurance\Fit\Profile\FieldTransform;
use Youmad\Endurance\Fit\Profile\FitTypeProfile;
use Youmad\Endurance\Fit\Profile\InMemoryFitProfileRegistry;
use Youmad\Endurance\Fit\Profile\InMemoryFitTypeRegistry;
use Youmad\Endurance\Fit\Profile\MessageProfile;
use Youmad\Endurance\Fit\Profile\SubfieldCondition;
use Youmad\Endurance\Fit\Profile\SubfieldProfile;
use Youmad\Endurance\Fit\Profile\TypeValueProfile;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\FitBaseType;
use Youmad\Endurance\Fit\Raw\MessageDefinition;
use Youmad\Endurance\Fit\Raw\RawDataMessage;
use Youmad\Endurance\Fit\Raw\RawFieldValue;
use Youmad\Endurance\Fit\Raw\RawStandardField;
use Youmad\Endurance\Fit\Raw\StandardFieldDefinition;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

final class FitDeviceInfoMessageMapperTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testMapsDescriptorAndStatusFromOneDeviceInfoMessage(): void
    {
        $items = (new FitDeviceInfoMessageMapper())->map(
            $this->message(
                fields: $this->completeFields(),
            ),
        );

        self::assertCount(2, $items);

        self::assertInstanceOf(
            DeviceItem::class,
            $items[0],
        );

        self::assertInstanceOf(
            DeviceStatusItem::class,
            $items[1],
        );

        $device = $items[0]->device;

        self::assertTrue($device->isDescribed());
        self::assertNotNull($device->descriptor);

        self::assertSame(
            'garmin',
            $device->descriptor->manufacturer,
        );

        self::assertSame(
            'edge_840',
            $device->descriptor->product,
        );

        self::assertSame(
            '1234567890',
            $device->descriptor->serialNumber,
        );

        self::assertSame(
            'Edge 840',
            $device->descriptor->productName,
        );

        self::assertSame(
            'Primary head unit',
            $device->descriptor->description,
        );

        $status = $items[1]->observation;

        self::assertTrue(
            $status->deviceId->equals($device->id),
        );

        self::assertNotNull($status->observedAt);

        self::assertSame(
            '2026-01-15T10:30:01+00:00',
            $status->observedAt
                ->toDateTimeImmutable()
                ->format(DATE_ATOM),
        );

        $this->assertText(
            status: $items[1],
            type: 'device_type',
            value: 'bike_power',
        );

        $this->assertText(
            status: $items[1],
            type: 'software_version',
            value: '19.22',
        );

        $this->assertText(
            status: $items[1],
            type: 'hardware_version',
            value: '3',
        );

        $this->assertText(
            status: $items[1],
            type: 'battery_status',
            value: 'good',
        );

        $this->assertText(
            status: $items[1],
            type: 'sensor_position',
            value: 'left_crank',
        );

        $this->assertText(
            status: $items[1],
            type: 'source_type',
            value: 'antplus',
        );

        $this->assertScalar(
            status: $items[1],
            type: 'cum_operating_time',
            value: 86_400,
            unit: 's',
        );

        $this->assertScalar(
            status: $items[1],
            type: 'battery_voltage',
            value: 3.921875,
            unit: 'V',
        );

        $this->assertScalar(
            status: $items[1],
            type: 'battery_level',
            value: 82,
            unit: '%',
        );
    }

    /**
     * @param array<
     *     int,
     *     array{base_type: int, bytes: string}
     * > $fields
     */
    private function message(array $fields): UnifiedDataMessage
    {
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
            types: $this->types(),
        )
        )->decode($raw);
    }

    private function profile(): MessageProfile
    {
        return MessageProfile::create(
            globalMessageNumber: 23,
            name: 'device_info',
            fields: [
                FieldProfile::create(
                    fieldNumber: 253,
                    name: 'timestamp',
                    typeName: 'date_time',
                    units: 's',
                ),
                FieldProfile::create(
                    fieldNumber: 0,
                    name: 'device_index',
                    typeName: 'device_index',
                ),
                FieldProfile::create(
                    fieldNumber: 1,
                    name: 'device_type',
                    typeName: 'uint8',
                    subfields: [
                        SubfieldProfile::create(
                            name: 'antplus_device_type',
                            typeName: 'antplus_device_type',
                            conditions: [
                                SubfieldCondition::create(
                                    referenceFieldNumber: 25,
                                    acceptedRawValues: [1],
                                ),
                            ],
                        ),
                    ],
                ),
                FieldProfile::create(
                    fieldNumber: 2,
                    name: 'manufacturer',
                    typeName: 'manufacturer',
                ),
                FieldProfile::create(
                    fieldNumber: 3,
                    name: 'serial_number',
                    typeName: 'uint32z',
                ),
                FieldProfile::create(
                    fieldNumber: 4,
                    name: 'product',
                    typeName: 'uint16',
                    subfields: [
                        SubfieldProfile::create(
                            name: 'garmin_product',
                            typeName: 'garmin_product',
                            conditions: [
                                SubfieldCondition::create(
                                    referenceFieldNumber: 2,
                                    acceptedRawValues: [1],
                                ),
                            ],
                        ),
                    ],
                ),
                FieldProfile::create(
                    fieldNumber: 5,
                    name: 'software_version',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 100,
                    ),
                ),
                FieldProfile::create(
                    fieldNumber: 6,
                    name: 'hardware_version',
                    typeName: 'uint8',
                ),
                FieldProfile::create(
                    fieldNumber: 7,
                    name: 'cum_operating_time',
                    typeName: 'uint32',
                    units: 's',
                ),
                FieldProfile::create(
                    fieldNumber: 10,
                    name: 'battery_voltage',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(
                        scale: 256,
                    ),
                    units: 'V',
                ),
                FieldProfile::create(
                    fieldNumber: 11,
                    name: 'battery_status',
                    typeName: 'battery_status',
                ),
                FieldProfile::create(
                    fieldNumber: 18,
                    name: 'sensor_position',
                    typeName: 'body_location',
                ),
                FieldProfile::create(
                    fieldNumber: 19,
                    name: 'descriptor',
                    typeName: 'string',
                ),
                FieldProfile::create(
                    fieldNumber: 20,
                    name: 'ant_transmission_type',
                    typeName: 'uint8z',
                ),
                FieldProfile::create(
                    fieldNumber: 21,
                    name: 'ant_device_number',
                    typeName: 'uint16z',
                ),
                FieldProfile::create(
                    fieldNumber: 22,
                    name: 'ant_network',
                    typeName: 'ant_network',
                ),
                FieldProfile::create(
                    fieldNumber: 25,
                    name: 'source_type',
                    typeName: 'source_type',
                ),
                FieldProfile::create(
                    fieldNumber: 27,
                    name: 'product_name',
                    typeName: 'string',
                ),
                FieldProfile::create(
                    fieldNumber: 32,
                    name: 'battery_level',
                    typeName: 'uint8',
                    units: '%',
                ),
            ],
        );
    }

    private function types(): InMemoryFitTypeRegistry
    {
        return new InMemoryFitTypeRegistry(
            types: [
                FitTypeProfile::create(
                    name: 'manufacturer',
                    values: [
                        TypeValueProfile::create(
                            value: 1,
                            name: 'garmin',
                        ),
                    ],
                ),
                FitTypeProfile::create(
                    name: 'garmin_product',
                    values: [
                        TypeValueProfile::create(
                            value: 3121,
                            name: 'edge_840',
                        ),
                    ],
                ),
                FitTypeProfile::create(
                    name: 'antplus_device_type',
                    values: [
                        TypeValueProfile::create(
                            value: 11,
                            name: 'bike_power',
                        ),
                    ],
                ),
                FitTypeProfile::create(
                    name: 'battery_status',
                    values: [
                        TypeValueProfile::create(
                            value: 2,
                            name: 'good',
                        ),
                    ],
                ),
                FitTypeProfile::create(
                    name: 'body_location',
                    values: [
                        TypeValueProfile::create(
                            value: 5,
                            name: 'left_crank',
                        ),
                    ],
                ),
                FitTypeProfile::create(
                    name: 'source_type',
                    values: [
                        TypeValueProfile::create(
                            value: 1,
                            name: 'antplus',
                        ),
                    ],
                ),
            ],
        );
    }

    /**
     * @return array<
     *     int,
     *     array{base_type: int, bytes: string}
     * >
     */
    private function completeFields(): array
    {
        return [
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
                'base_type' => 0x02,
                'bytes' => "\x01",
            ],
            1 => [
                'base_type' => 0x02,
                'bytes' => "\x0B",
            ],
            2 => [
                'base_type' => 0x84,
                'bytes' => pack('v', 1),
            ],
            3 => [
                'base_type' => 0x8C,
                'bytes' => pack('V', 1_234_567_890),
            ],
            4 => [
                'base_type' => 0x84,
                'bytes' => pack('v', 3121),
            ],
            5 => [
                'base_type' => 0x84,
                'bytes' => pack('v', 1922),
            ],
            6 => [
                'base_type' => 0x02,
                'bytes' => "\x03",
            ],
            7 => [
                'base_type' => 0x86,
                'bytes' => pack('V', 86_400),
            ],
            10 => [
                'base_type' => 0x84,
                'bytes' => pack('v', 1004),
            ],
            11 => [
                'base_type' => 0x02,
                'bytes' => "\x02",
            ],
            18 => [
                'base_type' => 0x00,
                'bytes' => "\x05",
            ],
            19 => [
                'base_type' => 0x07,
                'bytes' => "Primary head unit\0",
            ],
            25 => [
                'base_type' => 0x00,
                'bytes' => "\x01",
            ],
            27 => [
                'base_type' => 0x07,
                'bytes' => "Edge 840\0",
            ],
            32 => [
                'base_type' => 0x02,
                'bytes' => chr(82),
            ],
        ];
    }

    private function fitTimestamp(string $value): int
    {
        return (
            new \DateTimeImmutable($value)
            )->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }

    private function assertText(
        DeviceStatusItem $status,
        string $type,
        string $value,
    ): void {
        $measurement = $this->measurement(
            status: $status,
            type: $type,
        );

        self::assertInstanceOf(
            TextMeasurement::class,
            $measurement,
        );

        self::assertSame(
            $value,
            $measurement->value,
        );
    }

    private function measurement(
        DeviceStatusItem $status,
        string $type,
    ): ScalarMeasurement|TextMeasurement {
        foreach (
            $status->observation->measurements() as $measurement
        ) {
            if ($type === $measurement->type()->toString()) {
                self::assertTrue(
                    $measurement instanceof ScalarMeasurement
                    || $measurement instanceof TextMeasurement,
                );

                return $measurement;
            }
        }

        self::fail(
            sprintf(
                'Device status measurement %s was not found.',
                $type,
            ),
        );
    }

    private function assertScalar(
        DeviceStatusItem $status,
        string $type,
        int|float $value,
        string $unit,
    ): void {
        $measurement = $this->measurement(
            status: $status,
            type: $type,
        );

        self::assertInstanceOf(
            ScalarMeasurement::class,
            $measurement,
        );

        if (is_float($value)) {
            self::assertEqualsWithDelta(
                $value,
                $measurement->value,
                0.000000001,
            );
        } else {
            self::assertSame(
                $value,
                $measurement->value,
            );
        }

        self::assertSame(
            $unit,
            $measurement->unit->toString(),
        );
    }

    public function testRepeatedDeviceInfoKeepsIdAndDoesNotRepublishUnchangedDevice(): void
    {
        $mapper = new FitDeviceInfoMessageMapper();
        $message = $this->message(
            fields: $this->completeFields(),
        );

        $first = $mapper->map($message);
        $second = $mapper->map($message);

        self::assertCount(2, $first);
        self::assertCount(1, $second);

        self::assertInstanceOf(
            DeviceItem::class,
            $first[0],
        );

        self::assertInstanceOf(
            DeviceStatusItem::class,
            $first[1],
        );

        self::assertInstanceOf(
            DeviceStatusItem::class,
            $second[0],
        );

        self::assertTrue(
            $first[0]->device->id->equals(
                $second[0]->observation->deviceId,
            ),
        );
    }

    public function testLaterDescriptorEnrichesUnknownDeviceWithoutChangingId(): void
    {
        $mapper = new FitDeviceInfoMessageMapper();

        $unknown = $mapper->map(
            $this->message(
                fields: [
                    0 => [
                        'base_type' => 0x02,
                        'bytes' => "\x01",
                    ],
                    32 => [
                        'base_type' => 0x02,
                        'bytes' => chr(70),
                    ],
                ],
            ),
        );

        self::assertCount(2, $unknown);
        self::assertInstanceOf(DeviceItem::class, $unknown[0]);
        self::assertFalse($unknown[0]->device->isDescribed());

        $described = $mapper->map(
            $this->message(
                fields: [
                    0 => [
                        'base_type' => 0x02,
                        'bytes' => "\x01",
                    ],
                    2 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 1),
                    ],
                    27 => [
                        'base_type' => 0x07,
                        'bytes' => "Edge 840\0",
                    ],
                ],
            ),
        );

        self::assertCount(1, $described);
        self::assertInstanceOf(DeviceItem::class, $described[0]);
        self::assertTrue($described[0]->device->isDescribed());

        self::assertTrue(
            $unknown[0]->device->id->equals(
                $described[0]->device->id,
            ),
        );

        self::assertSame(
            'garmin',
            $described[0]
                ->device
                ->descriptor
                ?->manufacturer,
        );

        self::assertSame(
            'Edge 840',
            $described[0]
                ->device
                ->descriptor
                ->productName,
        );
    }

    public function testStandardStreamFlattensDeviceAndStatusItems(): void
    {
        $mapper = FitActivityImportItemMapper::standard();

        $items = iterator_to_array(
            $mapper->mapStream(
                [
                    $this->message(
                        fields: $this->completeFields(),
                    ),
                ],
            ),
            false,
        );

        self::assertCount(2, $items);
        self::assertInstanceOf(DeviceItem::class, $items[0]);
        self::assertInstanceOf(DeviceStatusItem::class, $items[1]);
    }

    public function testNewStreamResetsFileScopedDeviceIdentity(): void
    {
        $mapper = FitActivityImportItemMapper::standard();
        $message = $this->message(
            fields: [
                0 => [
                    'base_type' => 0x02,
                    'bytes' => "\x01",
                ],
                27 => [
                    'base_type' => 0x07,
                    'bytes' => "Edge 840\0",
                ],
            ],
        );

        $first = iterator_to_array(
            $mapper->mapStream([$message]),
            false,
        );

        $second = iterator_to_array(
            $mapper->mapStream([$message]),
            false,
        );

        self::assertInstanceOf(DeviceItem::class, $first[0]);
        self::assertInstanceOf(DeviceItem::class, $second[0]);

        self::assertFalse(
            $first[0]->device->id->equals(
                $second[0]->device->id,
            ),
        );
    }

    public function testSkipsDeviceInfoWithoutUsableDeviceIndexWithWarning(): void
    {
        $mapper = FitActivityImportItemMapper::standard();

        $items = iterator_to_array(
            $mapper->mapStream([
                $this->message(
                    fields: [
                        0 => [
                            'base_type' => 0x02,
                            'bytes' => "\xFF",
                        ],
                        32 => [
                            'base_type' => 0x02,
                            'bytes' => chr(82),
                        ],
                    ],
                ),
            ]),
            false,
        );

        self::assertSame([], $items);

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::DeviceInfoWithoutIndexSkipped,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'sequence' => 1,
                'byteOffset' => 20,
            ],
            $warnings[0]->context,
        );
    }
}
