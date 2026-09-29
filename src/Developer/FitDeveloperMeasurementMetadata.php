<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Developer;

use Youmad\Endurance\Activity\Telemetry\MeasurementMetadata;

final readonly class FitDeveloperMeasurementMetadata implements MeasurementMetadata
{
    public function __construct(
        public FitDeveloperApplicationIdentity $application,
        public int $fieldDefinitionNumber,
        public string $fieldName,
        public ?string $units,
        public ?int $nativeMessageNumber,
        public ?int $nativeFieldNumber,
        public ?int $componentIndex = null,
        public ?string $componentName = null,
        public ?int $componentBits = null,
        public bool $componentAccumulated = false,
    ) {
    }

    public function metadataType(): string
    {
        return 'fit.developer_field';
    }

    public function metadataVersion(): int
    {
        return 1;
    }

    /** @return array<string, mixed> */
    public function metadataData(): array
    {
        return [
            'application' => [
                'kind' => $this->application->kind->value,
                'identifier' => $this->application->identifier,
                'developer_data_index' => $this->application->developerDataIndex,
                'manufacturer_id' => $this->application->manufacturerId,
                'application_version' => $this->application->applicationVersion,
            ],
            'field_definition_number' => $this->fieldDefinitionNumber,
            'field_name' => $this->fieldName,
            'units' => $this->units,
            'native_message_number' => $this->nativeMessageNumber,
            'native_field_number' => $this->nativeFieldNumber,
            'component_index' => $this->componentIndex,
            'component_name' => $this->componentName,
            'component_bits' => $this->componentBits,
            'component_accumulated' => $this->componentAccumulated,
        ];
    }
}
