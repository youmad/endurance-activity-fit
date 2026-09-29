<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Session;

final readonly class FitSessionMeasurementRegistry
{
    /**
     * @var list<FitSessionScalarMeasurementDefinition>
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
            if (!$definition instanceof FitSessionScalarMeasurementDefinition) {
                throw new \InvalidArgumentException('FIT session scalar definitions must be FitSessionScalarMeasurementDefinition instances.');
            }

            $type = $definition
                ->measurementType
                ->toString();

            if (isset($knownTypes[$type])) {
                throw new \InvalidArgumentException(sprintf('FIT session measurement type %s is registered more than once.', $type));
            }

            $knownTypes[$type] = true;
            $validatedDefinitions[] = $definition;
        }

        $this->scalarDefinitions = $validatedDefinitions;
    }

    /**
     * @return list<FitSessionScalarMeasurementDefinition>
     */
    public function scalarDefinitions(): array
    {
        return $this->scalarDefinitions;
    }

    /**
     * @return list<FitSessionScalarMeasurementDefinition>
     */
    private static function standardDefinitions(): array
    {
        return [
            self::scalar('total_distance', ['total_distance'], 'm'),
            self::scalar('total_reps', ['total_reps'], 'reps'),
            self::scalar('total_strides', ['total_strides'], 'strides'),
            self::scalar('total_strokes', ['total_strokes'], 'strokes'),
            self::scalar('total_pushes', ['total_pushes'], 'pushes'),
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
            self::scalar('threshold_power', ['threshold_power'], 'W'),
            self::scalar('total_ascent', ['total_ascent'], 'm'),
            self::scalar('total_descent', ['total_descent'], 'm'),
            self::scalar('training_effect', ['total_training_effect'], null),
            self::scalar('anaerobic_training_effect', ['total_anaerobic_training_effect'], null),
            self::scalar('training_stress_score', ['training_stress_score'], 'tss'),
            self::scalar('intensity_factor', ['intensity_factor'], 'if'),
            self::scalar('total_work', ['total_work'], 'J'),
            self::scalar('average_altitude', ['enhanced_avg_altitude', 'avg_altitude'], 'm'),
            self::scalar('minimum_altitude', ['enhanced_min_altitude', 'min_altitude'], 'm'),
            self::scalar('maximum_altitude', ['enhanced_max_altitude', 'max_altitude'], 'm'),
            self::scalar('average_temperature', ['avg_temperature'], '°C'),
            self::scalar('minimum_temperature', ['min_temperature'], '°C'),
            self::scalar('maximum_temperature', ['max_temperature'], '°C'),
            self::scalar('total_moving_time', ['total_moving_time'], 's'),
            self::scalar('active_time', ['active_time'], 's'),
            self::scalar('lap_count', ['num_laps'], null),
            self::scalar('average_respiration_rate', ['enhanced_avg_respiration_rate', 'avg_respiration_rate'], 'breaths/min'),
            self::scalar('minimum_respiration_rate', ['enhanced_min_respiration_rate', 'min_respiration_rate'], 'breaths/min'),
            self::scalar('maximum_respiration_rate', ['enhanced_max_respiration_rate', 'max_respiration_rate'], 'breaths/min'),
            self::scalar('average_spo2', ['avg_spo2'], '%'),
            self::scalar('average_stress', ['avg_stress'], '%'),
            self::scalar('metabolic_calories', ['metabolic_calories'], 'kcal'),
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
    ): FitSessionScalarMeasurementDefinition {
        return FitSessionScalarMeasurementDefinition::create(
            measurementType: $measurementType,
            fieldNames: $fieldNames,
            unit: $unit,
        );
    }
}
