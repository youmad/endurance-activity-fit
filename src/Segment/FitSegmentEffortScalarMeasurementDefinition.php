<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Segment;

use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;

final readonly class FitSegmentEffortScalarMeasurementDefinition
{
    /**
     * @var non-empty-list<string>
     */
    private array $fieldNames;

    /**
     * @param non-empty-list<string> $fieldNames
     */
    private function __construct(
        public MeasurementType $measurementType,
        array $fieldNames,
        public MeasurementUnit $unit,
    ) {
        $this->fieldNames = $fieldNames;
    }

    /**
     * @param array<array-key, string> $fieldNames
     */
    public static function create(
        string $measurementType,
        array $fieldNames,
        ?string $unit,
    ): self {
        $fieldNames = array_values($fieldNames);

        if ([] === $fieldNames) {
            throw new \InvalidArgumentException('FIT segment effort scalar definition requires at least one field name.');
        }

        $knownFieldNames = [];

        foreach ($fieldNames as $fieldName) {
            if (
                1 !== preg_match(
                    '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/',
                    $fieldName,
                )
            ) {
                throw new \InvalidArgumentException(sprintf('FIT segment effort field name %s must use canonical snake_case notation.', $fieldName));
            }

            if (isset($knownFieldNames[$fieldName])) {
                throw new \InvalidArgumentException(sprintf('FIT segment effort scalar definition references field %s more than once.', $fieldName));
            }

            $knownFieldNames[$fieldName] = true;
        }

        return new self(
            measurementType: MeasurementType::fromString(
                $measurementType,
            ),
            fieldNames: $fieldNames,
            unit: null === $unit
                ? MeasurementUnit::none()
                : MeasurementUnit::fromSymbol($unit),
        );
    }

    /**
     * @return non-empty-list<string>
     */
    public function fieldNames(): array
    {
        return $this->fieldNames;
    }
}
