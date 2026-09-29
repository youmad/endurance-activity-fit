<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Record;

use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;

final readonly class FitRecordScalarMeasurementDefinition
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
        private ?string $fallbackAdditiveFieldName,
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
        ?string $fallbackAdditiveFieldName = null,
    ): self {
        $fieldNames = array_values($fieldNames);

        if ([] === $fieldNames) {
            throw new \InvalidArgumentException('FIT record scalar measurement must reference at least one field.');
        }

        $knownFieldNames = [];

        foreach ($fieldNames as $fieldName) {
            if (
                1 !== preg_match(
                    '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/',
                    $fieldName,
                )
            ) {
                throw new \InvalidArgumentException(sprintf('FIT record field name %s must use canonical snake_case notation.', $fieldName));
            }

            if (isset($knownFieldNames[$fieldName])) {
                throw new \InvalidArgumentException(sprintf('FIT record scalar measurement references field %s more than once.', $fieldName));
            }

            $knownFieldNames[$fieldName] = true;
        }

        if (null !== $fallbackAdditiveFieldName) {
            if (
                1 !== preg_match(
                    '/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/',
                    $fallbackAdditiveFieldName,
                )
                || isset($knownFieldNames[$fallbackAdditiveFieldName])
            ) {
                throw new \InvalidArgumentException(sprintf('FIT record additive field %s must be a distinct canonical field name.', $fallbackAdditiveFieldName));
            }
        }

        return new self(
            measurementType: MeasurementType::fromString(
                $measurementType,
            ),
            fieldNames: $fieldNames,
            unit: null === $unit
                ? MeasurementUnit::none()
                : MeasurementUnit::fromSymbol($unit),
            fallbackAdditiveFieldName: $fallbackAdditiveFieldName,
        );
    }

    /**
     * Fields are ordered from the most preferred representation to
     * the legacy or lower-resolution fallback.
     *
     * @return non-empty-list<string>
     */
    public function fieldNames(): array
    {
        return $this->fieldNames;
    }

    /**
     * Some legacy FIT representations store an additional fractional part in
     * a separate field. The additive field applies only when the least
     * preferred fallback representation was selected.
     */
    public function fallbackAdditiveFieldName(
        string $selectedFieldName,
    ): ?string {
        $fallbackFieldName = $this->fieldNames[
            count($this->fieldNames) - 1
        ];

        return $selectedFieldName === $fallbackFieldName
            ? $this->fallbackAdditiveFieldName
            : null;
    }
}
