<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\PoolSwimming;

use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;

final readonly class FitPoolLengthScalarMeasurementDefinition
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
        public bool $activeOnly,
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
        bool $activeOnly = false,
    ): self {
        if ([] === $fieldNames) {
            throw new \InvalidArgumentException('FIT pool length scalar definition requires at least one field name.');
        }

        foreach ($fieldNames as $fieldName) {
            if (
                1 !== preg_match(
                    '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/',
                    $fieldName,
                )
            ) {
                throw new \InvalidArgumentException('FIT pool length field names must use canonical snake_case notation.');
            }
        }

        return new self(
            measurementType: MeasurementType::fromString(
                $measurementType,
            ),
            fieldNames: array_values($fieldNames),
            unit: null === $unit
                ? MeasurementUnit::none()
                : MeasurementUnit::fromSymbol($unit),
            activeOnly: $activeOnly,
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
