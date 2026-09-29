<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Developer;

use Youmad\Endurance\Activity\Telemetry\ArrayMeasurement;
use Youmad\Endurance\Activity\Telemetry\Measurement;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\MeasurementSource;
use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\Telemetry\TextMeasurement;
use Youmad\Endurance\Fit\Developer\DeveloperComponentProfile;
use Youmad\Endurance\Fit\Developer\DeveloperDataProfile;
use Youmad\Endurance\Fit\Developer\DeveloperFieldProfile;
use Youmad\Endurance\Fit\Typed\TypedEnumFieldElement;
use Youmad\Endurance\Fit\Typed\TypedFieldElements;
use Youmad\Endurance\Fit\Typed\TypedFieldValue;
use Youmad\Endurance\Fit\Typed\TypedInvalidFieldElement;
use Youmad\Endurance\Fit\Typed\TypedScalarFieldElement;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Fit\Unified\UnifiedDeveloperComponent;
use Youmad\Endurance\Fit\Unified\UnifiedDeveloperField;

final readonly class FitDeveloperMeasurementMapper
{
    /**
     * @var \WeakMap<
     *     DeveloperDataProfile,
     *     FitDeveloperApplicationIdentity
     * >
     */
    private \WeakMap $applicationIdentities;

    /**
     * @var \WeakMap<
     *     DeveloperFieldProfile,
     *     \WeakMap<
     *         DeveloperDataProfile,
     *         array{
     *             type: MeasurementType,
     *             unit: MeasurementUnit,
     *             metadata: FitDeveloperMeasurementMetadata
     *         }
     *     >
     * >
     */
    private \WeakMap $fieldDescriptors;

    /**
     * @var \WeakMap<
     *     DeveloperComponentProfile,
     *     \WeakMap<
     *         DeveloperDataProfile,
     *         array{
     *             type: MeasurementType,
     *             unit: MeasurementUnit,
     *             metadata: FitDeveloperMeasurementMetadata
     *         }
     *     >
     * >
     */
    private \WeakMap $componentDescriptors;

    public function __construct(
        private FitDeveloperMeasurementTypeFactory $types =
            new FitDeveloperMeasurementTypeFactory(),
    ) {
        $this->applicationIdentities = new \WeakMap();
        $this->fieldDescriptors = new \WeakMap();
        $this->componentDescriptors = new \WeakMap();
    }

    /**
     * @return list<MeasurementReading>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array {
        return $this->mapFields($message->developerFields());
    }

    /**
     * @param list<UnifiedDeveloperField> $fields
     *
     * @return list<MeasurementReading>
     *
     * @internal optimized Record pipeline entry point
     */
    public function mapFields(array $fields): array
    {
        $readings = [];

        foreach ($fields as $field) {
            if ($field->hasComponents()) {
                foreach ($field->components() as $component) {
                    $reading = $this->mapComponent(
                        field: $field,
                        component: $component,
                    );

                    if (null !== $reading) {
                        $readings[] = $reading;
                    }
                }

                continue;
            }

            $reading = $this->mapField($field);

            if (null !== $reading) {
                $readings[] = $reading;
            }
        }

        return $readings;
    }

    private function mapField(
        UnifiedDeveloperField $field,
    ): ?MeasurementReading {
        if (
            !$field->isResolved()
            || null === $field->profile
            || null === $field->developerData
            || null === $field->value
        ) {
            return null;
        }

        $descriptor = $this->fieldDescriptor($field);

        if (null === $descriptor) {
            return null;
        }

        return $this->reading(
            value: $field->value,
            type: $descriptor['type'],
            unit: $descriptor['unit'],
            metadata: $descriptor['metadata'],
        );
    }

    private function mapComponent(
        UnifiedDeveloperField $field,
        UnifiedDeveloperComponent $component,
    ): ?MeasurementReading {
        if (
            !$field->isResolved()
            || null === $field->profile
            || null === $field->developerData
        ) {
            return null;
        }

        $descriptor = $this->componentDescriptor(
            field: $field,
            component: $component,
        );

        if (null === $descriptor) {
            return null;
        }

        return $this->reading(
            value: $component->value,
            type: $descriptor['type'],
            unit: $descriptor['unit'],
            metadata: $descriptor['metadata'],
        );
    }

    private function reading(
        TypedFieldValue $value,
        MeasurementType $type,
        MeasurementUnit $unit,
        FitDeveloperMeasurementMetadata $metadata,
    ): ?MeasurementReading {
        $values = $this->values($value);

        if (null === $values) {
            return null;
        }

        $measurement = $this->measurement(
            type: $type,
            values: $values,
            unit: $unit,
        );

        if (null === $measurement) {
            return null;
        }

        return MeasurementReading::reported(
            measurement: $measurement,
            source: MeasurementSource::unknown(),
            metadata: $metadata,
        );
    }

    /**
     * @return array{
     *     type: MeasurementType,
     *     unit: MeasurementUnit,
     *     metadata: FitDeveloperMeasurementMetadata
     * }|null
     */
    private function fieldDescriptor(
        UnifiedDeveloperField $field,
    ): ?array {
        $profile = $field->profile;
        $developerData = $field->developerData;

        if (
            null === $profile
            || null === $developerData
        ) {
            return null;
        }

        $descriptors = $this->fieldDescriptors[$profile]
            ?? null;

        if (null === $descriptors) {
            /** @var \WeakMap<DeveloperDataProfile, array{type: MeasurementType, unit: MeasurementUnit, metadata: FitDeveloperMeasurementMetadata}> $descriptors */
            $descriptors = new \WeakMap();
            $this->fieldDescriptors[$profile] = $descriptors;
        }

        if (isset($descriptors[$developerData])) {
            return $descriptors[$developerData];
        }

        $identity = $this->applicationIdentity($developerData);
        $type = $this->types->create($field);

        if (
            null === $identity
            || null === $type
        ) {
            return null;
        }

        $units = $field->units();
        $descriptor = [
            'type' => $type,
            'unit' => $this->unit($units),
            'metadata' => new FitDeveloperMeasurementMetadata(
                application: clone $identity,
                fieldDefinitionNumber: $field->fieldDefinitionNumber(),
                fieldName: $profile->name,
                units: $units,
                nativeMessageNumber: $profile->nativeMessageNumber,
                nativeFieldNumber: $profile->nativeFieldNumber,
            ),
        ];

        $descriptors[$developerData] = $descriptor;

        return $descriptor;
    }

    /**
     * @return array{
     *     type: MeasurementType,
     *     unit: MeasurementUnit,
     *     metadata: FitDeveloperMeasurementMetadata
     * }|null
     */
    private function componentDescriptor(
        UnifiedDeveloperField $field,
        UnifiedDeveloperComponent $component,
    ): ?array {
        $profile = $field->profile;
        $developerData = $field->developerData;

        if (
            null === $profile
            || null === $developerData
        ) {
            return null;
        }

        $descriptors = $this->componentDescriptors[
            $component->profile
        ] ?? null;

        if (null === $descriptors) {
            /** @var \WeakMap<DeveloperDataProfile, array{type: MeasurementType, unit: MeasurementUnit, metadata: FitDeveloperMeasurementMetadata}> $descriptors */
            $descriptors = new \WeakMap();
            $this->componentDescriptors[
                $component->profile
            ] = $descriptors;
        }

        if (isset($descriptors[$developerData])) {
            return $descriptors[$developerData];
        }

        $identity = $this->applicationIdentity($developerData);
        $type = $this->types->createComponent(
            field: $field,
            component: $component,
        );

        if (
            null === $identity
            || null === $type
        ) {
            return null;
        }

        $units = $component->units();
        $descriptor = [
            'type' => $type,
            'unit' => $this->unit($units),
            'metadata' => new FitDeveloperMeasurementMetadata(
                application: clone $identity,
                fieldDefinitionNumber: $field->fieldDefinitionNumber(),
                fieldName: $profile->name,
                units: $units,
                nativeMessageNumber: $profile->nativeMessageNumber,
                nativeFieldNumber: $profile->nativeFieldNumber,
                componentIndex: $component->componentIndex,
                componentName: $component->name(),
                componentBits: $component->profile->bits,
                componentAccumulated: $component->profile->accumulated,
            ),
        ];

        $descriptors[$developerData] = $descriptor;

        return $descriptor;
    }

    private function applicationIdentity(
        DeveloperDataProfile $profile,
    ): ?FitDeveloperApplicationIdentity {
        if (isset($this->applicationIdentities[$profile])) {
            return $this->applicationIdentities[$profile];
        }

        $identity = FitDeveloperApplicationIdentity::fromProfile(
            $profile,
        );

        if (null !== $identity) {
            $this->applicationIdentities[$profile] = $identity;
        }

        return $identity;
    }

    private function unit(?string $units): MeasurementUnit
    {
        return null === $units || '' === trim($units)
            ? MeasurementUnit::none()
            : MeasurementUnit::fromSymbol($units);
    }

    /**
     * @return non-empty-list<int|float|string|null>|null
     */
    private function values(
        TypedFieldValue $typed,
    ): ?array {
        if (!$typed instanceof TypedFieldElements) {
            return null;
        }

        $values = [];
        $hasValue = false;

        foreach ($typed->elements() as $element) {
            if ($element instanceof TypedInvalidFieldElement) {
                $values[] = null;

                continue;
            }

            if ($element instanceof TypedEnumFieldElement) {
                $value = $element->name()
                    ?? $element->value;

                $values[] = $value;
                $hasValue = true;

                continue;
            }

            if ($element instanceof TypedScalarFieldElement) {
                $value = $element->value();

                if (is_string($value)) {
                    $value = trim($value);

                    if ('' === $value) {
                        $values[] = null;

                        continue;
                    }
                }

                $values[] = $value;
                $hasValue = true;
            }
        }

        if (!$hasValue) {
            return null;
        }

        /* @var non-empty-list<int|float|string|null> $values */
        return $values;
    }

    /**
     * @param non-empty-list<int|float|string|null> $values
     */
    private function measurement(
        MeasurementType $type,
        array $values,
        MeasurementUnit $unit,
    ): ?Measurement {
        if (1 < count($values)) {
            return new ArrayMeasurement(
                measurementType: $type,
                values: $values,
                unit: $unit,
            );
        }

        $value = $values[0];

        if (
            is_int($value)
            || is_float($value)
        ) {
            return new ScalarMeasurement(
                measurementType: $type,
                value: $value,
                unit: $unit,
            );
        }

        if (is_string($value)) {
            return new TextMeasurement(
                measurementType: $type,
                value: $value,
            );
        }

        return null;
    }
}
