<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Developer;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperMeasurementMapper;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperMeasurementMetadata;
use Youmad\Endurance\ActivityFit\Tests\Fixture\TestFitProfile;
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

final class FitDeveloperComponentMeasurementTest extends TestCase
{
    public function testMapsDeveloperComponentsInsteadOfPackedContainer(): void
    {
        $messages = array_values(
            iterator_to_array(
                FitDecoder::standard(TestFitProfile::load())->decodeStream(
                    [
                        $this->developerDataIdMessage(),
                        $this->fieldDescriptionMessage(),
                        $this->recordMessage(
                            sequence: 2,
                            instant: 5,
                            total: 250,
                        ),
                        $this->recordMessage(
                            sequence: 3,
                            instant: 6,
                            total: 3,
                        ),
                    ],
                ),
            ),
        );

        $readings = (new FitDeveloperMeasurementMapper())->map(
            $messages[3],
        );

        self::assertCount(2, $readings);

        $instant = $readings[0];

        self::assertInstanceOf(
            ScalarMeasurement::class,
            $instant->measurement,
        );

        self::assertSame(
            'fit_developer_application_a0a1a2a3a4a5a6a7a8a9aaabacadaeaf_0_packed_metrics_component_0_instant',
            $instant->measurement->type()->toString(),
        );

        self::assertEqualsWithDelta(
            0.6,
            $instant->measurement->value,
            0.000000001,
        );

        self::assertSame(
            'units',
            $instant->measurement->unit->toString(),
        );

        $instantMetadata = $instant->metadata;

        self::assertInstanceOf(
            FitDeveloperMeasurementMetadata::class,
            $instantMetadata,
        );

        self::assertSame(0, $instantMetadata->componentIndex);
        self::assertSame('instant', $instantMetadata->componentName);
        self::assertSame(8, $instantMetadata->componentBits);
        self::assertFalse($instantMetadata->componentAccumulated);

        $total = $readings[1];

        self::assertInstanceOf(
            ScalarMeasurement::class,
            $total->measurement,
        );

        self::assertSame(
            'fit_developer_application_a0a1a2a3a4a5a6a7a8a9aaabacadaeaf_0_packed_metrics_component_1_total',
            $total->measurement->type()->toString(),
        );

        self::assertEqualsWithDelta(
            25.9,
            $total->measurement->value,
            0.000000001,
        );

        $totalMetadata = $total->metadata;

        self::assertInstanceOf(
            FitDeveloperMeasurementMetadata::class,
            $totalMetadata,
        );

        self::assertSame(1, $totalMetadata->componentIndex);
        self::assertSame('total', $totalMetadata->componentName);
        self::assertSame(8, $totalMetadata->componentBits);
        self::assertTrue($totalMetadata->componentAccumulated);
    }

    private function developerDataIdMessage(): RawDataMessage
    {
        $definitions = [];
        $bytes = [];

        $applicationId = range(0xA0, 0xAF);

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 1,
            size: count($applicationId),
            baseType: 0x0D,
            value: pack('C*', ...$applicationId),
        );

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 3,
            size: 1,
            baseType: 0x02,
            value: "\x00",
        );

        return $this->message(
            sequence: 0,
            localMessageNumber: 0,
            globalMessageNumber: 207,
            standardDefinitions: $definitions,
            standardBytes: $bytes,
        );
    }

    private function fieldDescriptionMessage(): RawDataMessage
    {
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
            value: "\x00",
        );

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 2,
            size: 1,
            baseType: 0x02,
            value: chr(0x0D),
        );

        $this->appendStringField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 3,
            value: 'Packed Metrics',
            size: 24,
        );

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 4,
            size: 1,
            baseType: 0x02,
            value: "\x01",
        );

        $this->appendStringField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 5,
            value: 'instant,total',
            size: 24,
        );

        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 6,
            size: 1,
            baseType: 0x02,
            value: chr(10),
        );

        $this->appendStringField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 8,
            value: 'units',
            size: 8,
        );

        $this->appendStringField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 9,
            value: '8,8',
            size: 8,
        );

        $this->appendStringField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: 10,
            value: '0,1',
            size: 8,
        );

        return $this->message(
            sequence: 1,
            localMessageNumber: 1,
            globalMessageNumber: 206,
            standardDefinitions: $definitions,
            standardBytes: $bytes,
        );
    }

    private function recordMessage(
        int $sequence,
        int $instant,
        int $total,
    ): RawDataMessage {
        $definition = DeveloperFieldDefinition::create(
            fieldNumber: 0,
            size: 2,
            developerDataIndex: 0,
        );

        return $this->message(
            sequence: $sequence,
            localMessageNumber: 2,
            globalMessageNumber: 20,
            standardDefinitions: [],
            standardBytes: [],
            developerDefinitions: [$definition],
            developerBytes: [chr($instant).chr($total)],
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

    /**
     * @param list<StandardFieldDefinition> $definitions
     * @param list<string>                  $bytes
     */
    private function appendStringField(
        array &$definitions,
        array &$bytes,
        int $fieldNumber,
        string $value,
        int $size,
    ): void {
        $this->appendStandardField(
            definitions: $definitions,
            bytes: $bytes,
            fieldNumber: $fieldNumber,
            size: $size,
            baseType: 0x07,
            value: str_pad(
                $value."\0",
                $size,
                "\0",
            ),
        );
    }
}
