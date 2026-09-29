<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Lap;

final readonly class FitLapMeasurementRegistry
{
    /**
     * @var list<FitLapScalarMeasurementDefinition>
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
            if (!$definition instanceof FitLapScalarMeasurementDefinition) {
                throw new \InvalidArgumentException('FIT lap scalar definitions must be FitLapScalarMeasurementDefinition instances.');
            }

            $type = $definition
                ->measurementType
                ->toString();

            if (isset($knownTypes[$type])) {
                throw new \InvalidArgumentException(sprintf('FIT lap measurement type %s is registered more than once.', $type));
            }

            $knownTypes[$type] = true;
            $validatedDefinitions[] = $definition;
        }

        $this->scalarDefinitions = $validatedDefinitions;
    }

    /**
     * @return list<FitLapScalarMeasurementDefinition>
     */
    public function scalarDefinitions(): array
    {
        return $this->scalarDefinitions;
    }

    /**
     * @return list<FitLapScalarMeasurementDefinition>
     */
    private static function standardDefinitions(): array
    {
        return [
            self::scalar('total_distance', ['total_distance'], 'm'),
            self::scalar('total_strides', ['total_strides'], 'strides'),
            self::scalar('total_strokes', ['total_strokes'], 'strokes'),
            self::scalar('total_cycles', ['total_cycles'], 'cycles'),
            self::scalar('total_calories', ['total_calories'], 'kcal'),
            self::scalar('total_fat_calories', ['total_fat_calories'], 'kcal'),
            self::scalar('average_speed', ['enhanced_avg_speed', 'avg_speed'], 'm/s'),
            self::scalar('maximum_speed', ['enhanced_max_speed', 'max_speed'], 'm/s'),
            self::scalar('average_heart_rate', ['avg_heart_rate'], 'bpm'),
            self::scalar('minimum_heart_rate', ['min_heart_rate'], 'bpm'),
            self::scalar('maximum_heart_rate', ['max_heart_rate'], 'bpm'),
            self::scalar('average_running_cadence', ['avg_running_cadence'], 'strides/min'),
            self::scalar('average_cadence', ['avg_cadence'], 'rpm'),
            self::scalar('maximum_running_cadence', ['max_running_cadence'], 'strides/min'),
            self::scalar('maximum_cadence', ['max_cadence'], 'rpm'),
            self::scalar('average_power', ['avg_power'], 'W'),
            self::scalar('maximum_power', ['max_power'], 'W'),
            self::scalar('normalized_power', ['normalized_power'], 'W'),
            self::scalar('total_ascent', ['total_ascent'], 'm'),
            self::scalar('total_descent', ['total_descent'], 'm'),
            self::scalar('total_work', ['total_work'], 'J'),
            self::scalar('average_altitude', ['enhanced_avg_altitude', 'avg_altitude'], 'm'),
            self::scalar('minimum_altitude', ['enhanced_min_altitude', 'min_altitude'], 'm'),
            self::scalar('maximum_altitude', ['enhanced_max_altitude', 'max_altitude'], 'm'),
            self::scalar('average_temperature', ['avg_temperature'], '°C'),
            self::scalar('minimum_temperature', ['min_temperature'], '°C'),
            self::scalar('maximum_temperature', ['max_temperature'], '°C'),
            self::scalar('total_moving_time', ['total_moving_time'], 's'),
            self::scalar('active_time', ['active_time'], 's'),
            self::scalar('average_respiration_rate', ['enhanced_avg_respiration_rate', 'avg_respiration_rate'], 'breaths/min'),
            self::scalar('minimum_respiration_rate', ['min_respiration_rate'], 'breaths/min'),
            self::scalar('maximum_respiration_rate', ['enhanced_max_respiration_rate', 'max_respiration_rate'], 'breaths/min'),
            self::scalar('average_core_temperature', ['avg_core_temperature'], '°C'),
            self::scalar('minimum_core_temperature', ['min_core_temperature'], '°C'),
            self::scalar('maximum_core_temperature', ['max_core_temperature'], '°C'),
        ];
    }

    /**
     * @param non-empty-list<string> $fieldNames
     */
    private static function scalar(
        string $measurementType,
        array $fieldNames,
        ?string $unit,
    ): FitLapScalarMeasurementDefinition {
        return FitLapScalarMeasurementDefinition::create(
            measurementType: $measurementType,
            fieldNames: $fieldNames,
            unit: $unit,
        );
    }
}
