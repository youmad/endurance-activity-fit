<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Segment;

final readonly class FitSegmentEffortMeasurementRegistry
{
    /**
     * @var list<FitSegmentEffortScalarMeasurementDefinition>
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
            if (!$definition instanceof FitSegmentEffortScalarMeasurementDefinition) {
                throw new \InvalidArgumentException('FIT segment effort scalar definitions must be FitSegmentEffortScalarMeasurementDefinition instances.');
            }

            $type = $definition
                ->measurementType
                ->toString();

            if (isset($knownTypes[$type])) {
                throw new \InvalidArgumentException(sprintf('FIT segment effort measurement type %s is registered more than once.', $type));
            }

            $knownTypes[$type] = true;
            $validatedDefinitions[] = $definition;
        }

        $this->scalarDefinitions = $validatedDefinitions;
    }

    /**
     * @return list<FitSegmentEffortScalarMeasurementDefinition>
     */
    public function scalarDefinitions(): array
    {
        return $this->scalarDefinitions;
    }

    /**
     * @return list<FitSegmentEffortScalarMeasurementDefinition>
     */
    private static function standardDefinitions(): array
    {
        return [
            self::scalar('total_distance', ['total_distance'], 'm'),
            self::scalar('total_strokes', ['total_strokes'], 'strokes'),
            self::scalar('total_cycles', ['total_cycles'], 'cycles'),
            self::scalar('total_calories', ['total_calories'], 'kcal'),
            self::scalar('total_fat_calories', ['total_fat_calories'], 'kcal'),
            self::scalar('average_speed', ['avg_speed'], 'm/s'),
            self::scalar('maximum_speed', ['max_speed'], 'm/s'),
            self::scalar('average_heart_rate', ['avg_heart_rate'], 'bpm'),
            self::scalar('minimum_heart_rate', ['min_heart_rate'], 'bpm'),
            self::scalar('maximum_heart_rate', ['max_heart_rate'], 'bpm'),
            self::scalar('average_cadence', ['avg_cadence'], 'rpm'),
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
            self::scalar('gps_accuracy', ['gps_accuracy'], 'm'),
            self::scalar('average_grade', ['avg_grade'], '%'),
            self::scalar('average_positive_grade', ['avg_pos_grade'], '%'),
            self::scalar('average_negative_grade', ['avg_neg_grade'], '%'),
            self::scalar('maximum_positive_grade', ['max_pos_grade'], '%'),
            self::scalar('maximum_negative_grade', ['max_neg_grade'], '%'),
            self::scalar('average_temperature', ['avg_temperature'], '°C'),
            self::scalar('maximum_temperature', ['max_temperature'], '°C'),
            self::scalar('total_moving_time', ['total_moving_time'], 's'),
            self::scalar('average_positive_vertical_speed', ['avg_pos_vertical_speed'], 'm/s'),
            self::scalar('average_negative_vertical_speed', ['avg_neg_vertical_speed'], 'm/s'),
            self::scalar('maximum_positive_vertical_speed', ['max_pos_vertical_speed'], 'm/s'),
            self::scalar('maximum_negative_vertical_speed', ['max_neg_vertical_speed'], 'm/s'),
            self::scalar('active_time', ['active_time'], 's'),
            self::scalar('average_left_torque_effectiveness', ['avg_left_torque_effectiveness'], '%'),
            self::scalar('average_right_torque_effectiveness', ['avg_right_torque_effectiveness'], '%'),
            self::scalar('average_left_pedal_smoothness', ['avg_left_pedal_smoothness'], '%'),
            self::scalar('average_right_pedal_smoothness', ['avg_right_pedal_smoothness'], '%'),
            self::scalar('average_combined_pedal_smoothness', ['avg_combined_pedal_smoothness'], '%'),
            self::scalar('front_gear_shift_count', ['front_gear_shift_count'], null),
            self::scalar('rear_gear_shift_count', ['rear_gear_shift_count'], null),
            self::scalar('time_standing', ['time_standing'], 's'),
            self::scalar('stand_count', ['stand_count'], null),
            self::scalar('average_left_platform_center_offset', ['avg_left_pco'], 'mm'),
            self::scalar('average_right_platform_center_offset', ['avg_right_pco'], 'mm'),
            self::scalar('total_grit', ['total_grit'], 'kGrit'),
            self::scalar('total_flow', ['total_flow'], 'Flow'),
            self::scalar('average_grit', ['avg_grit'], 'kGrit'),
            self::scalar('average_flow', ['avg_flow'], 'Flow'),
        ];
    }

    /**
     * @param non-empty-list<string> $fieldNames
     */
    private static function scalar(
        string $measurementType,
        array $fieldNames,
        ?string $unit,
    ): FitSegmentEffortScalarMeasurementDefinition {
        return FitSegmentEffortScalarMeasurementDefinition::create(
            measurementType: $measurementType,
            fieldNames: $fieldNames,
            unit: $unit,
        );
    }
}
