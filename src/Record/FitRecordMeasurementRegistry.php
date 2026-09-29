<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Record;

final readonly class FitRecordMeasurementRegistry
{
    /**
     * @var list<FitRecordScalarMeasurementDefinition>
     */
    private array $scalarDefinitions;

    /**
     * @var array<
     *     string,
     *     array<int, FitRecordScalarMeasurementDefinition>
     * >
     */
    private array $scalarDefinitionsByFieldName;

    /**
     * Passing null selects the standard FIT record mappings. Passing
     * an explicit array, including an empty one, creates a custom set.
     *
     * @param array<array-key, mixed>|null $scalarDefinitions
     */
    public function __construct(
        ?array $scalarDefinitions = null,
    ) {
        $scalarDefinitions ??= self::standardDefinitions();
        $scalarDefinitions = array_values($scalarDefinitions);

        $knownMeasurementTypes = [];
        $scalarDefinitionsByFieldName = [];
        $validatedDefinitions = [];

        foreach ($scalarDefinitions as $index => $definition) {
            if (!$definition instanceof FitRecordScalarMeasurementDefinition) {
                throw new \InvalidArgumentException('FIT record scalar definitions must be FitRecordScalarMeasurementDefinition instances.');
            }

            $measurementType = $definition
                ->measurementType
                ->toString();

            if (isset($knownMeasurementTypes[$measurementType])) {
                throw new \InvalidArgumentException(sprintf('FIT record measurement type %s is registered more than once.', $measurementType));
            }

            $knownMeasurementTypes[$measurementType] = true;
            $validatedDefinitions[] = $definition;

            foreach ($definition->fieldNames() as $fieldName) {
                $scalarDefinitionsByFieldName[$fieldName][$index] =
                    $definition;
            }
        }

        $this->scalarDefinitions = $validatedDefinitions;
        $this->scalarDefinitionsByFieldName =
            $scalarDefinitionsByFieldName;
    }

    /**
     * @return list<FitRecordScalarMeasurementDefinition>
     */
    public function scalarDefinitions(): array
    {
        return $this->scalarDefinitions;
    }

    /**
     * Return only definitions that can possibly resolve one of the supplied
     * field names, while preserving the registry's canonical definition order.
     *
     * @param list<string> $fieldNames
     *
     * @return list<FitRecordScalarMeasurementDefinition>
     */
    public function scalarDefinitionsForFieldNames(
        array $fieldNames,
    ): array {
        $definitions = [];

        foreach ($fieldNames as $fieldName) {
            foreach (
                $this->scalarDefinitionsByFieldName[$fieldName] ?? [] as $index => $definition
            ) {
                $definitions[$index] = $definition;
            }
        }

        if ([] === $definitions) {
            return [];
        }

        ksort($definitions, SORT_NUMERIC);

        return array_values($definitions);
    }

    public function scalarDefinition(
        string $measurementType,
    ): ?FitRecordScalarMeasurementDefinition {
        foreach ($this->scalarDefinitions as $definition) {
            if (
                $measurementType
                === $definition
                    ->measurementType
                    ->toString()
            ) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return list<FitRecordScalarMeasurementDefinition>
     */
    private static function standardDefinitions(): array
    {
        return [
            ...self::coreDefinitions(),
            ...self::runningDefinitions(),
            ...self::cyclingDefinitions(),
            ...self::physiologyDefinitions(),
            ...self::environmentDefinitions(),
            ...self::mountainBikeDefinitions(),
            ...self::electricBikeDefinitions(),
            ...self::divingDefinitions(),
        ];
    }

    /**
     * @return list<FitRecordScalarMeasurementDefinition>
     */
    private static function coreDefinitions(): array
    {
        return [
            self::scalar(
                measurementType: 'altitude',
                fieldNames: [
                    'enhanced_altitude',
                    'altitude',
                ],
                unit: 'm',
            ),
            self::scalar(
                measurementType: 'heart_rate',
                fieldNames: ['heart_rate'],
                unit: 'bpm',
            ),
            self::scalar(
                measurementType: 'cadence',
                fieldNames: [
                    'cadence256',
                    'cadence',
                ],
                unit: 'rpm',
                fallbackAdditiveFieldName: 'fractional_cadence',
            ),
            self::scalar(
                measurementType: 'distance',
                fieldNames: ['distance'],
                unit: 'm',
            ),
            self::scalar(
                measurementType: 'speed',
                fieldNames: [
                    'enhanced_speed',
                    'speed',
                ],
                unit: 'm/s',
            ),
            self::scalar(
                measurementType: 'power',
                fieldNames: ['power'],
                unit: 'W',
            ),
            self::scalar(
                measurementType: 'grade',
                fieldNames: ['grade'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'temperature',
                fieldNames: ['temperature'],
                unit: '°C',
            ),
            self::scalar(
                measurementType: 'vertical_speed',
                fieldNames: ['vertical_speed'],
                unit: 'm/s',
            ),
            self::scalar(
                measurementType: 'calories',
                fieldNames: ['calories'],
                unit: 'kcal',
            ),
            self::scalar(
                measurementType: 'gps_accuracy',
                fieldNames: ['gps_accuracy'],
                unit: 'm',
            ),
            self::scalar(
                measurementType: 'resistance',
                fieldNames: ['resistance'],
                unit: null,
            ),
            self::scalar(
                measurementType: 'time_from_course',
                fieldNames: ['time_from_course'],
                unit: 's',
            ),
            self::scalar(
                measurementType: 'cycle_length',
                fieldNames: [
                    'cycle_length16',
                    'cycle_length',
                ],
                unit: 'm',
            ),
            self::scalar(
                measurementType: 'total_cycles',
                fieldNames: ['total_cycles'],
                unit: 'cycles',
            ),
            self::scalar(
                measurementType: 'zone',
                fieldNames: ['zone'],
                unit: null,
            ),
            self::scalar(
                measurementType: 'ball_speed',
                fieldNames: ['ball_speed'],
                unit: 'm/s',
            ),
        ];
    }

    /**
     * @return list<FitRecordScalarMeasurementDefinition>
     */
    private static function runningDefinitions(): array
    {
        return [
            self::scalar(
                measurementType: 'vertical_oscillation',
                fieldNames: ['vertical_oscillation'],
                unit: 'mm',
            ),
            self::scalar(
                measurementType: 'stance_time_percent',
                fieldNames: ['stance_time_percent'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'stance_time',
                fieldNames: ['stance_time'],
                unit: 'ms',
            ),
            self::scalar(
                measurementType: 'vertical_ratio',
                fieldNames: ['vertical_ratio'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'stance_time_balance',
                fieldNames: ['stance_time_balance'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'step_length',
                fieldNames: ['step_length'],
                unit: 'mm',
            ),
        ];
    }

    /**
     * @return list<FitRecordScalarMeasurementDefinition>
     */
    private static function cyclingDefinitions(): array
    {
        return [
            self::scalar(
                measurementType: 'left_torque_effectiveness',
                fieldNames: ['left_torque_effectiveness'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'right_torque_effectiveness',
                fieldNames: ['right_torque_effectiveness'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'left_pedal_smoothness',
                fieldNames: ['left_pedal_smoothness'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'right_pedal_smoothness',
                fieldNames: ['right_pedal_smoothness'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'combined_pedal_smoothness',
                fieldNames: ['combined_pedal_smoothness'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'left_pco',
                fieldNames: ['left_pco'],
                unit: 'mm',
            ),
            self::scalar(
                measurementType: 'right_pco',
                fieldNames: ['right_pco'],
                unit: 'mm',
            ),
            self::scalar(
                measurementType: 'battery_soc',
                fieldNames: ['battery_soc'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'motor_power',
                fieldNames: ['motor_power'],
                unit: 'W',
            ),
        ];
    }

    /**
     * @return list<FitRecordScalarMeasurementDefinition>
     */
    private static function physiologyDefinitions(): array
    {
        return [
            self::scalar(
                measurementType: 'total_hemoglobin_conc',
                fieldNames: ['total_hemoglobin_conc'],
                unit: 'g/dL',
            ),
            self::scalar(
                measurementType: 'total_hemoglobin_conc_min',
                fieldNames: ['total_hemoglobin_conc_min'],
                unit: 'g/dL',
            ),
            self::scalar(
                measurementType: 'total_hemoglobin_conc_max',
                fieldNames: ['total_hemoglobin_conc_max'],
                unit: 'g/dL',
            ),
            self::scalar(
                measurementType: 'saturated_hemoglobin_percent',
                fieldNames: ['saturated_hemoglobin_percent'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'saturated_hemoglobin_percent_min',
                fieldNames: ['saturated_hemoglobin_percent_min'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'saturated_hemoglobin_percent_max',
                fieldNames: ['saturated_hemoglobin_percent_max'],
                unit: '%',
            ),
            // Legacy record.respiration_rate is measured in seconds and is
            // not unit-compatible with enhanced_respiration_rate.
            self::scalar(
                measurementType: 'respiration_rate',
                fieldNames: ['enhanced_respiration_rate'],
                unit: 'breaths/min',
            ),
            self::scalar(
                measurementType: 'current_stress',
                fieldNames: ['current_stress'],
                unit: null,
            ),
            self::scalar(
                measurementType: 'core_temperature',
                fieldNames: ['core_temperature'],
                unit: '°C',
            ),
        ];
    }

    /**
     * @return list<FitRecordScalarMeasurementDefinition>
     */
    private static function environmentDefinitions(): array
    {
        return [
            self::scalar(
                measurementType: 'absolute_pressure',
                fieldNames: ['absolute_pressure'],
                unit: 'Pa',
            ),
        ];
    }

    /**
     * @return list<FitRecordScalarMeasurementDefinition>
     */
    private static function mountainBikeDefinitions(): array
    {
        return [
            self::scalar(
                measurementType: 'grit',
                fieldNames: ['grit'],
                unit: null,
            ),
            self::scalar(
                measurementType: 'flow',
                fieldNames: ['flow'],
                unit: null,
            ),
        ];
    }

    /**
     * @return list<FitRecordScalarMeasurementDefinition>
     */
    private static function electricBikeDefinitions(): array
    {
        return [
            self::scalar(
                measurementType: 'ebike_travel_range',
                fieldNames: ['ebike_travel_range'],
                unit: 'km',
            ),
            self::scalar(
                measurementType: 'ebike_battery_level',
                fieldNames: ['ebike_battery_level'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'ebike_assist_level_percent',
                fieldNames: ['ebike_assist_level_percent'],
                unit: '%',
            ),
        ];
    }

    /**
     * @return list<FitRecordScalarMeasurementDefinition>
     */
    private static function divingDefinitions(): array
    {
        return [
            self::scalar(
                measurementType: 'depth',
                fieldNames: ['depth'],
                unit: 'm',
            ),
            self::scalar(
                measurementType: 'next_stop_depth',
                fieldNames: ['next_stop_depth'],
                unit: 'm',
            ),
            self::scalar(
                measurementType: 'next_stop_time',
                fieldNames: ['next_stop_time'],
                unit: 's',
            ),
            self::scalar(
                measurementType: 'time_to_surface',
                fieldNames: ['time_to_surface'],
                unit: 's',
            ),
            self::scalar(
                measurementType: 'ndl_time',
                fieldNames: ['ndl_time'],
                unit: 's',
            ),
            self::scalar(
                measurementType: 'cns_load',
                fieldNames: ['cns_load'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'n2_load',
                fieldNames: ['n2_load'],
                unit: '%',
            ),
            self::scalar(
                measurementType: 'air_time_remaining',
                fieldNames: ['air_time_remaining'],
                unit: 's',
            ),
            self::scalar(
                measurementType: 'pressure_sac',
                fieldNames: ['pressure_sac'],
                unit: 'bar/min',
            ),
            self::scalar(
                measurementType: 'volume_sac',
                fieldNames: ['volume_sac'],
                unit: 'L/min',
            ),
            self::scalar(
                measurementType: 'rmv',
                fieldNames: ['rmv'],
                unit: 'L/min',
            ),
            self::scalar(
                measurementType: 'ascent_rate',
                fieldNames: ['ascent_rate'],
                unit: 'm/s',
            ),
            self::scalar(
                measurementType: 'po2',
                fieldNames: ['po2'],
                unit: '%',
            ),
        ];
    }

    /**
     * @param non-empty-list<string> $fieldNames
     */
    private static function scalar(
        string $measurementType,
        array $fieldNames,
        ?string $unit,
        ?string $fallbackAdditiveFieldName = null,
    ): FitRecordScalarMeasurementDefinition {
        return FitRecordScalarMeasurementDefinition::create(
            measurementType: $measurementType,
            fieldNames: $fieldNames,
            unit: $unit,
            fallbackAdditiveFieldName: $fallbackAdditiveFieldName,
        );
    }
}
