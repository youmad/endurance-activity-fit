<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\PoolSwimming;

final readonly class FitPoolLengthMeasurementRegistry
{
    /**
     * @var list<FitPoolLengthScalarMeasurementDefinition>
     */
    private array $scalarDefinitions;

    /**
     * @param array<array-key, mixed>|null $scalarDefinitions
     */
    public function __construct(
        ?array $scalarDefinitions = null,
    ) {
        $scalarDefinitions ??= self::standardDefinitions();
        $scalarDefinitions = array_values($scalarDefinitions);
        $knownTypes = [];
        $validatedDefinitions = [];

        foreach ($scalarDefinitions as $definition) {
            if (!$definition instanceof FitPoolLengthScalarMeasurementDefinition) {
                throw new \InvalidArgumentException('FIT pool length scalar definitions must be FitPoolLengthScalarMeasurementDefinition instances.');
            }

            $type = $definition
                ->measurementType
                ->toString();

            if (isset($knownTypes[$type])) {
                throw new \InvalidArgumentException(sprintf('FIT pool length measurement type %s is registered more than once.', $type));
            }

            $knownTypes[$type] = true;
            $validatedDefinitions[] = $definition;
        }

        $this->scalarDefinitions = $validatedDefinitions;
    }

    /**
     * @return list<FitPoolLengthScalarMeasurementDefinition>
     */
    public function scalarDefinitions(): array
    {
        return $this->scalarDefinitions;
    }

    /**
     * @return list<FitPoolLengthScalarMeasurementDefinition>
     */
    private static function standardDefinitions(): array
    {
        return [
            self::scalar('stroke_count', ['total_strokes'], 'strokes', activeOnly: true),
            self::scalar('average_speed', ['avg_speed'], 'm/s', activeOnly: true),
            self::scalar('average_swimming_cadence', ['avg_swimming_cadence'], 'strokes/min', activeOnly: true),
            self::scalar('total_calories', ['total_calories'], 'kcal'),
            self::scalar('average_respiration_rate', ['enhanced_avg_respiration_rate', 'avg_respiration_rate'], 'breaths/min'),
            self::scalar('maximum_respiration_rate', ['enhanced_max_respiration_rate', 'max_respiration_rate'], 'breaths/min'),
        ];
    }

    /**
     * @param non-empty-list<string> $fieldNames
     */
    private static function scalar(
        string $measurementType,
        array $fieldNames,
        ?string $unit,
        bool $activeOnly = false,
    ): FitPoolLengthScalarMeasurementDefinition {
        return FitPoolLengthScalarMeasurementDefinition::create(
            measurementType: $measurementType,
            fieldNames: $fieldNames,
            unit: $unit,
            activeOnly: $activeOnly,
        );
    }
}
