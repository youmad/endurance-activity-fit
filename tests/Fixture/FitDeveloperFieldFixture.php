<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Fixture;

use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Raw\DeveloperFieldDefinition;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\FitBaseType;
use Youmad\Endurance\Fit\Raw\MessageDefinition;
use Youmad\Endurance\Fit\Raw\RawDataMessage;
use Youmad\Endurance\Fit\Raw\RawDeveloperField;
use Youmad\Endurance\Fit\Raw\RawFieldValue;
use Youmad\Endurance\Fit\Raw\RawStandardField;
use Youmad\Endurance\Fit\Raw\StandardFieldDefinition;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

final readonly class FitDeveloperFieldFixture
{
    /**
     * @return list<RawDataMessage>
     */
    public function completeRecordRawMessages(): array
    {
        return [
            $this->developerDataIdMessage(
                applicationId: range(0xA0, 0xAF),
                developerId: range(0x01, 0x10),
            ),
            $this->fieldDescriptionMessage(
                sequence: 1,
                fieldDefinitionNumber: 0,
                baseTypeId: 0x84,
                fieldName: 'Vertical Oscillation',
                scale: 10,
                units: 'mm',
                nativeMessageNumber: 20,
                nativeFieldNumber: 39,
            ),
            $this->fieldDescriptionMessage(
                sequence: 2,
                fieldDefinitionNumber: 1,
                baseTypeId: 0x07,
                fieldName: 'Mood Label',
            ),
            $this->fieldDescriptionMessage(
                sequence: 3,
                fieldDefinitionNumber: 2,
                baseTypeId: 0x02,
                fieldName: 'Power Zones',
                units: 'W',
                isArray: true,
            ),
            $this->recordMessage(sequence: 4),
        ];
    }

    /**
     * @param list<int>|null $applicationId
     * @param list<int>|null $developerId
     */
    public function completeRecord(
        ?array $applicationId = null,
        ?array $developerId = null,
        string $scalarUnits = 'mm',
    ): UnifiedDataMessage {
        return $this->recordWithIdentity(
            applicationId: $applicationId
                ?? range(0xA0, 0xAF),
            developerId: $developerId
                ?? range(0x01, 0x10),
            scalarUnits: $scalarUnits,
        );
    }

    public function recordUsingDeveloperId(): UnifiedDataMessage
    {
        return $this->recordWithIdentity(
            applicationId: null,
            developerId: range(0x01, 0x10),
        );
    }

    public function recordWithoutStableIdentity(): UnifiedDataMessage
    {
        $messages = array_values(
            iterator_to_array(
                FitDecoder::standard(TestFitProfile::load())->decodeStream(
                    [
                        $this->developerDataIdMessage(
                            applicationId: null,
                            developerId: null,
                        ),
                        $this->fieldDescriptionMessage(
                            sequence: 1,
                            fieldDefinitionNumber: 0,
                            baseTypeId: 0x84,
                            fieldName: 'Vertical Oscillation',
                            scale: 10,
                            units: 'mm',
                        ),
                        $this->recordMessage(
                            sequence: 2,
                            includeAllDeveloperFields: false,
                        ),
                    ],
                ),
            ),
        );

        return $messages[2];
    }

    public function unresolvedRecord(): UnifiedDataMessage
    {
        return array_values(
            iterator_to_array(
                FitDecoder::standard(TestFitProfile::load())->decodeStream(
                    [
                        $this->recordMessage(
                            sequence: 0,
                            includeAllDeveloperFields: false,
                        ),
                    ],
                ),
            ),
        )[0];
    }

    /**
     * @param list<int>|null $applicationId
     * @param list<int>|null $developerId
     */
    private function recordWithIdentity(
        ?array $applicationId,
        ?array $developerId,
        string $scalarUnits = 'mm',
    ): UnifiedDataMessage {
        $messages = array_values(
            iterator_to_array(
                FitDecoder::standard(TestFitProfile::load())->decodeStream(
                    [
                        $this->developerDataIdMessage(
                            applicationId: $applicationId,
                            developerId: $developerId,
                        ),
                        $this->fieldDescriptionMessage(
                            sequence: 1,
                            fieldDefinitionNumber: 0,
                            baseTypeId: 0x84,
                            fieldName: 'Vertical Oscillation',
                            scale: 10,
                            units: $scalarUnits,
                            nativeMessageNumber: 20,
                            nativeFieldNumber: 39,
                        ),
                        $this->fieldDescriptionMessage(
                            sequence: 2,
                            fieldDefinitionNumber: 1,
                            baseTypeId: 0x07,
                            fieldName: 'Mood Label',
                        ),
                        $this->fieldDescriptionMessage(
                            sequence: 3,
                            fieldDefinitionNumber: 2,
                            baseTypeId: 0x02,
                            fieldName: 'Power Zones',
                            units: 'W',
                            isArray: true,
                        ),
                        $this->recordMessage(sequence: 4),
                    ],
                ),
            ),
        );

        return $messages[4];
    }

    /**
     * @param list<int>|null $applicationId
     * @param list<int>|null $developerId
     */
    private function developerDataIdMessage(
        ?array $applicationId,
        ?array $developerId,
    ): RawDataMessage {
        $definitions = [];
        $bytes = [];

        if (null !== $developerId) {
            $this->appendStandardField(
                definitions: $definitions,
                bytes: $bytes,
                fieldNumber: 0,
                size: count($developerId),
                baseType: 0x0D,
                value: pack('C*', ...$developerId),
            );
        }

        if (null !== $applicationId) {
            $this->appendStandardField(
                definitions: $definitions,
                bytes: $bytes,
                fieldNumber: 1,
                size: count($applicationId),
                baseType: 0x0D,
                value: pack('C*', ...$applicationId),
            );
        }

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 2,
            size: 2,
            baseType: 0x84,
            value: pack('v', 1),
        );

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 3,
            size: 1,
            baseType: 0x02,
            value: "\x00",
        );

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 4,
            size: 4,
            baseType: 0x86,
            value: pack('V', 110),
        );

        return $this->message(
            sequence: 0,
            localMessageNumber: 0,
            globalMessageNumber: 207,
            standardDefinitions: $definitions,
            standardBytes: $bytes,
        );
    }

    private function fieldDescriptionMessage(
        int $sequence,
        int $fieldDefinitionNumber,
        int $baseTypeId,
        string $fieldName,
        ?int $scale = null,
        ?string $units = null,
        bool $isArray = false,
        ?int $nativeMessageNumber = null,
        ?int $nativeFieldNumber = null,
    ): RawDataMessage {
        $definitions = [];
        $bytes = [];

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 0,
            size: 1,
            baseType: 0x02,
            value: "\x00",
        );

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 1,
            size: 1,
            baseType: 0x02,
            value: chr($fieldDefinitionNumber),
        );

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 2,
            size: 1,
            baseType: 0x02,
            value: chr($baseTypeId),
        );

        $fieldNameBytes = $this->fitString(
            value: $fieldName,
            size: 32,
        );

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 3,
            size: strlen($fieldNameBytes),
            baseType: 0x07,
            value: $fieldNameBytes,
        );

        if ($isArray) {
            $this->appendStandardField(
                definitions: $definitions,
                bytes: $bytes,
                fieldNumber: 4,
                size: 1,
                baseType: 0x02,
                value: "\x01",
            );
        }

        if (null !== $scale) {
            $this->appendStandardField(
                definitions: $definitions,
                bytes: $bytes,
                fieldNumber: 6,
                size: 1,
                baseType: 0x02,
                value: chr($scale),
            );
        }

        if (null !== $units) {
            $unitBytes = $this->fitString(
                value: $units,
                size: 8,
            );

            $this->appendStandardField(
                definitions: $definitions,
                bytes: $bytes,
                fieldNumber: 8,
                size: strlen($unitBytes),
                baseType: 0x07,
                value: $unitBytes,
            );
        }

        if (null !== $nativeMessageNumber) {
            $this->appendStandardField(
                definitions: $definitions,
                bytes: $bytes,
                fieldNumber: 14,
                size: 2,
                baseType: 0x84,
                value: pack('v', $nativeMessageNumber),
            );
        }

        if (null !== $nativeFieldNumber) {
            $this->appendStandardField(
                definitions: $definitions,
                bytes: $bytes,
                fieldNumber: 15,
                size: 1,
                baseType: 0x02,
                value: chr($nativeFieldNumber),
            );
        }

        return $this->message(
            sequence: $sequence,
            localMessageNumber: 1,
            globalMessageNumber: 206,
            standardDefinitions: $definitions,
            standardBytes: $bytes,
        );
    }

    private function recordMessage(
        int $sequence,
        bool $includeAllDeveloperFields = true,
    ): RawDataMessage {
        $timestamp = StandardFieldDefinition::create(
            fieldNumber: 253,
            size: 4,
            baseType: FitBaseType::fromDefinitionByte(0x86),
        );

        $definitions = [
            DeveloperFieldDefinition::create(
                fieldNumber: 0,
                size: 2,
                developerDataIndex: 0,
            ),
        ];

        $bytes = [pack('v', 1234)];

        if ($includeAllDeveloperFields) {
            $definitions[] = DeveloperFieldDefinition::create(
                fieldNumber: 1,
                size: 12,
                developerDataIndex: 0,
            );

            $bytes[] = $this->fitString(
                value: 'great',
                size: 12,
            );

            $definitions[] = DeveloperFieldDefinition::create(
                fieldNumber: 2,
                size: 3,
                developerDataIndex: 0,
            );

            $bytes[] = "\x01\xFF\x03";
        }

        return $this->message(
            sequence: $sequence,
            localMessageNumber: 2,
            globalMessageNumber: 20,
            standardDefinitions: [$timestamp],
            standardBytes: [pack('V', 1_000)],
            developerDefinitions: $definitions,
            developerBytes: $bytes,
        );
    }

    /**
     * @param list<StandardFieldDefinition>  $standardDefinitions
     * @param list<string>                   $standardBytes
     * @param list<DeveloperFieldDefinition> $developerDefinitions
     * @param list<string>                   $developerBytes
     */
    private function message(
        int $sequence,
        int $localMessageNumber,
        int $globalMessageNumber,
        array $standardDefinitions,
        array $standardBytes,
        array $developerDefinitions = [],
        array $developerBytes = [],
    ): RawDataMessage {
        $definition = MessageDefinition::create(
            localMessageNumber: $localMessageNumber,
            architecture: FitArchitecture::LittleEndian,
            globalMessageNumber: $globalMessageNumber,
            standardFields: $standardDefinitions,
            developerFields: $developerDefinitions,
        );

        $standardFields = [];

        foreach ($standardDefinitions as $index => $field) {
            $standardFields[] = new RawStandardField(
                definition: $field,
                value: RawFieldValue::fromBytes(
                    $standardBytes[$index],
                ),
            );
        }

        $developerFields = [];

        foreach ($developerDefinitions as $index => $field) {
            $developerFields[] = new RawDeveloperField(
                definition: $field,
                value: RawFieldValue::fromBytes(
                    $developerBytes[$index],
                ),
            );
        }

        return RawDataMessage::create(
            sequenceNumber: $sequence,
            byteOffset: $sequence * 100,
            recordHeaderByte: $localMessageNumber,
            definition: $definition,
            standardFields: $standardFields,
            developerFields: $developerFields,
        );
    }

    /**
     * @param list<StandardFieldDefinition> $definitions
     * @param list<string>                  $bytes
     */
    private function appendStandardField(
        array &$definitions,
        array &$bytes,
        int $fieldNumber,
        int $size,
        int $baseType,
        string $value,
    ): void {
        $definitions[] = StandardFieldDefinition::create(
            fieldNumber: $fieldNumber,
            size: $size,
            baseType: FitBaseType::fromDefinitionByte(
                $baseType,
            ),
        );

        $bytes[] = $value;
    }

    private function fitString(
        string $value,
        int $size,
    ): string {
        return str_pad(
            $value."\0",
            $size,
            "\0",
        );
    }
}
