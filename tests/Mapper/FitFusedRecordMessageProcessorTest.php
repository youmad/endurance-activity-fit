<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitFieldValueReader;
use Youmad\Endurance\ActivityFit\Mapper\FitFusedRecordMessageProcessor;
use Youmad\Endurance\Fit\Decoder\FitComponentExtractor;
use Youmad\Endurance\Fit\Decoder\FitComponentValueResolver;
use Youmad\Endurance\Fit\Decoder\FitDataMessageDecoder;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Decoder\FitDeveloperFieldResolver;
use Youmad\Endurance\Fit\Decoder\FitProfileNormalizer;
use Youmad\Endurance\Fit\Profile\ComponentProfile;
use Youmad\Endurance\Fit\Profile\FieldProfile;
use Youmad\Endurance\Fit\Profile\FieldTransform;
use Youmad\Endurance\Fit\Profile\InMemoryFitProfileRegistry;
use Youmad\Endurance\Fit\Profile\InMemoryFitTypeRegistry;
use Youmad\Endurance\Fit\Profile\MessageProfile;
use Youmad\Endurance\Fit\Profile\SubfieldCondition;
use Youmad\Endurance\Fit\Profile\SubfieldProfile;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\FitBaseType;
use Youmad\Endurance\Fit\Raw\MessageDefinition;
use Youmad\Endurance\Fit\Raw\RawDataMessage;
use Youmad\Endurance\Fit\Raw\RawFieldValue;
use Youmad\Endurance\Fit\Raw\RawStandardField;
use Youmad\Endurance\Fit\Raw\StandardFieldDefinition;

final class FitFusedRecordMessageProcessorTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testRetainsTimestampForRecordWithoutMeasurements(): void
    {
        $this->assertMatchesReference(
            profile: MessageProfile::create(
                globalMessageNumber: 20,
                name: 'record',
                fields: [$this->timestampProfile()],
            ),
            fields: [253 => ['base_type' => 0x86, 'bytes' => pack('V', 1_200_000_000)]],
        );
    }

    public function testMatchesReferenceForComponentsAndAdditiveCadence(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                $this->timestampProfile(),
                FieldProfile::create(
                    fieldNumber: 6,
                    name: 'speed',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(scale: 1000),
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
                            transform: FieldTransform::scaleAndOffset(scale: 100),
                            units: 'm/s',
                        ),
                    ],
                ),
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
                    transform: FieldTransform::scaleAndOffset(scale: 128),
                    units: 'rpm',
                ),
            ],
        );

        $this->assertMatchesReference(
            profile: $profile,
            fields: [
                253 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', $this->fitTimestamp('2026-01-15T10:30:01Z')),
                ],
                8 => [
                    'base_type' => 0x84,
                    'bytes' => pack('v', 850),
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
        );
    }

    public function testMatchesReferenceForSelectedProfileSubfield(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                $this->timestampProfile(),
                FieldProfile::create(
                    fieldNumber: 0,
                    name: 'sport_selector',
                    typeName: 'uint8',
                ),
                FieldProfile::create(
                    fieldNumber: 200,
                    name: 'custom_value',
                    typeName: 'uint8',
                    subfields: [
                        SubfieldProfile::create(
                            name: 'heart_rate',
                            typeName: 'uint8',
                            units: 'bpm',
                            conditions: [
                                SubfieldCondition::create(
                                    referenceFieldNumber: 0,
                                    acceptedRawValues: [1],
                                ),
                            ],
                        ),
                    ],
                ),
            ],
        );

        $this->assertMatchesReference(
            profile: $profile,
            fields: [
                253 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', $this->fitTimestamp('2026-01-15T10:30:01Z')),
                ],
                0 => [
                    'base_type' => 0x02,
                    'bytes' => "\x01",
                ],
                200 => [
                    'base_type' => 0x02,
                    'bytes' => chr(150),
                ],
            ],
        );
    }

    public function testMatchesReferenceEnhancedFallbackAndCadence256Priority(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                $this->timestampProfile(),
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
                    fieldNumber: 4,
                    name: 'cadence',
                    typeName: 'uint8',
                    units: 'rpm',
                ),
                FieldProfile::create(
                    fieldNumber: 53,
                    name: 'fractional_cadence',
                    typeName: 'uint8',
                    transform: FieldTransform::scaleAndOffset(scale: 128),
                    units: 'rpm',
                ),
                FieldProfile::create(
                    fieldNumber: 200,
                    name: 'cadence256',
                    typeName: 'uint16',
                    transform: FieldTransform::scaleAndOffset(scale: 256),
                    units: 'rpm',
                ),
            ],
        );

        $this->assertMatchesReference(
            profile: $profile,
            fields: [
                253 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', $this->fitTimestamp('2026-01-15T10:30:01Z')),
                ],
                78 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', 0xFFFFFFFF),
                ],
                2 => [
                    'base_type' => 0x84,
                    'bytes' => pack('v', 2_500),
                ],
                200 => [
                    'base_type' => 0x84,
                    'bytes' => pack('v', 22_400),
                ],
                4 => [
                    'base_type' => 0x02,
                    'bytes' => chr(80),
                ],
                53 => [
                    'base_type' => 0x02,
                    'bytes' => chr(64),
                ],
            ],
        );
    }

    public function testMatchesReferenceWhenMissingTimestampHasAmbiguousCoordinates(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                FieldProfile::create(
                    fieldNumber: 0,
                    name: 'position_lat',
                    typeName: 'sint32',
                    units: 'semicircles',
                ),
                FieldProfile::create(
                    fieldNumber: 1,
                    name: 'position_lat',
                    typeName: 'sint32',
                    units: 'semicircles',
                ),
            ],
        );

        $this->assertMatchesReference(
            profile: $profile,
            fields: [
                0 => [
                    'base_type' => 0x85,
                    'bytes' => pack('V', 123456 & 0xFFFFFFFF),
                ],
                1 => [
                    'base_type' => 0x85,
                    'bytes' => pack('V', 654321 & 0xFFFFFFFF),
                ],
            ],
        );
    }

    public function testMatchesReferenceWarningOrderForSkippedRecord(): void
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 20,
            name: 'record',
            fields: [
                FieldProfile::create(
                    fieldNumber: 0,
                    name: 'position_lat',
                    typeName: 'sint32',
                    units: 'semicircles',
                ),
            ],
        );

        $this->assertMatchesReference(
            profile: $profile,
            fields: [
                0 => [
                    'base_type' => 0x85,
                    'bytes' => pack('V', 123456 & 0xFFFFFFFF),
                ],
            ],
        );
    }

    /**
     * @param array<int, array{base_type: int, bytes: string}> $fields
     */
    private function assertMatchesReference(
        MessageProfile $profile,
        array $fields,
    ): void {
        $raw = $this->rawMessage($profile, $fields);

        $referenceProfiles = new InMemoryFitProfileRegistry($profile);
        $referenceTypes = new InMemoryFitTypeRegistry();
        $referenceMessage = (new FitDecoder(
            profiles: $referenceProfiles,
            types: $referenceTypes,
        ))->decode($raw);
        $referenceMapper = FitActivityImportItemMapper::standard();
        $referenceItems = $referenceMapper->map($referenceMessage);
        $referenceWarnings = $referenceMapper->warnings();

        $fusedProfiles = new InMemoryFitProfileRegistry($profile);
        $fusedTypes = new InMemoryFitTypeRegistry();
        $fused = new FitFusedRecordMessageProcessor(
            decoder: new FitDataMessageDecoder(),
            profiles: new FitProfileNormalizer($fusedProfiles),
            components: new FitComponentExtractor(),
            componentValues: new FitComponentValueResolver(),
            developerFields: new FitDeveloperFieldResolver(
                profiles: $fusedProfiles,
            ),
        );
        $projection = $fused->process($raw);

        self::assertSame(
            (new FitFieldValueReader())->byName($referenceMessage, 'timestamp')?->value,
            $projection->timestamp,
        );
        self::assertSame(
            serialize([$referenceItems, $referenceWarnings]),
            serialize([$projection->items, $projection->warnings]),
        );
    }

    /**
     * @param array<int, array{base_type: int, bytes: string}> $fields
     */
    private function rawMessage(
        MessageProfile $profile,
        array $fields,
    ): RawDataMessage {
        $definitions = [];
        $rawFields = [];

        foreach ($fields as $fieldNumber => $field) {
            $definition = StandardFieldDefinition::create(
                fieldNumber: $fieldNumber,
                size: strlen($field['bytes']),
                baseType: FitBaseType::fromDefinitionByte($field['base_type']),
            );
            $definitions[] = $definition;
            $rawFields[] = new RawStandardField(
                definition: $definition,
                value: RawFieldValue::fromBytes($field['bytes']),
            );
        }

        $definition = MessageDefinition::create(
            localMessageNumber: 0,
            architecture: FitArchitecture::LittleEndian,
            globalMessageNumber: $profile->globalMessageNumber,
            standardFields: $definitions,
        );

        return RawDataMessage::create(
            sequenceNumber: 1,
            byteOffset: 20,
            recordHeaderByte: 0x00,
            definition: $definition,
            standardFields: $rawFields,
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

    private function fitTimestamp(string $value): int
    {
        return (new \DateTimeImmutable($value))->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }
}
