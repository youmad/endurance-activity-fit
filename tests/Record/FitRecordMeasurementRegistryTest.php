<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Record;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityFit\Record\FitRecordMeasurementRegistry;
use Youmad\Endurance\ActivityFit\Record\FitRecordScalarMeasurementDefinition;

final class FitRecordMeasurementRegistryTest extends TestCase
{
    public function testStandardRegistryContainsRepresentativeSportGroups(): void
    {
        $registry = new FitRecordMeasurementRegistry();

        $this->assertDefinition(
            registry: $registry,
            measurementType: 'speed',
            fieldNames: [
                'enhanced_speed',
                'speed',
            ],
            unit: 'm/s',
        );

        $cadence = $registry->scalarDefinition('cadence');

        self::assertNotNull($cadence);
        self::assertSame(
            'fractional_cadence',
            $cadence->fallbackAdditiveFieldName('cadence'),
        );
        self::assertNull(
            $cadence->fallbackAdditiveFieldName('cadence256'),
        );

        $this->assertDefinition(
            registry: $registry,
            measurementType: 'vertical_oscillation',
            fieldNames: ['vertical_oscillation'],
            unit: 'mm',
        );

        $this->assertDefinition(
            registry: $registry,
            measurementType: 'left_torque_effectiveness',
            fieldNames: ['left_torque_effectiveness'],
            unit: '%',
        );

        $this->assertDefinition(
            registry: $registry,
            measurementType: 'respiration_rate',
            fieldNames: ['enhanced_respiration_rate'],
            unit: 'breaths/min',
        );

        $this->assertDefinition(
            registry: $registry,
            measurementType: 'ebike_battery_level',
            fieldNames: ['ebike_battery_level'],
            unit: '%',
        );

        $this->assertDefinition(
            registry: $registry,
            measurementType: 'depth',
            fieldNames: ['depth'],
            unit: 'm',
        );
    }

    public function testCustomDefinitionsReplaceStandardSet(): void
    {
        $custom = FitRecordScalarMeasurementDefinition::create(
            measurementType: 'custom_metric',
            fieldNames: ['custom_field'],
            unit: 'custom-unit',
        );

        $registry = new FitRecordMeasurementRegistry(
            scalarDefinitions: [$custom],
        );

        self::assertSame(
            [$custom],
            $registry->scalarDefinitions(),
        );

        self::assertSame(
            $custom,
            $registry->scalarDefinition(
                'custom_metric',
            ),
        );

        self::assertNull(
            $registry->scalarDefinition('speed'),
        );
    }

    public function testFiltersDefinitionsByAvailableFieldNamesInRegistryOrder(): void
    {
        $speed = FitRecordScalarMeasurementDefinition::create(
            measurementType: 'speed',
            fieldNames: [
                'enhanced_speed',
                'speed',
            ],
            unit: 'm/s',
        );

        $power = FitRecordScalarMeasurementDefinition::create(
            measurementType: 'power',
            fieldNames: ['power'],
            unit: 'W',
        );

        $grade = FitRecordScalarMeasurementDefinition::create(
            measurementType: 'grade',
            fieldNames: ['grade'],
            unit: '%',
        );

        $registry = new FitRecordMeasurementRegistry(
            scalarDefinitions: [
                $speed,
                $power,
                $grade,
            ],
        );

        self::assertSame(
            [
                $speed,
                $grade,
            ],
            $registry->scalarDefinitionsForFieldNames([
                'grade',
                'enhanced_speed',
                'enhanced_speed',
                'unknown_field',
            ]),
        );
    }

    public function testDoesNotSelectDefinitionForAdditiveFieldAlone(): void
    {
        $registry = new FitRecordMeasurementRegistry();

        self::assertSame(
            [],
            $registry->scalarDefinitionsForFieldNames([
                'fractional_cadence',
            ]),
        );
    }

    public function testRejectsDuplicateMeasurementType(): void
    {
        $first = FitRecordScalarMeasurementDefinition::create(
            measurementType: 'speed',
            fieldNames: ['speed'],
            unit: 'm/s',
        );

        $second = FitRecordScalarMeasurementDefinition::create(
            measurementType: 'speed',
            fieldNames: ['enhanced_speed'],
            unit: 'm/s',
        );

        $this->expectException(
            \InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'measurement type speed is registered more than once',
        );

        new FitRecordMeasurementRegistry(
            scalarDefinitions: [
                $first,
                $second,
            ],
        );
    }

    public function testDefinitionRejectsDuplicateFieldName(): void
    {
        $this->expectException(
            \InvalidArgumentException::class,
        );

        $this->expectExceptionMessage(
            'references field speed more than once',
        );

        FitRecordScalarMeasurementDefinition::create(
            measurementType: 'speed',
            fieldNames: [
                'speed',
                'speed',
            ],
            unit: 'm/s',
        );
    }

    /**
     * @param non-empty-list<string> $fieldNames
     */
    private function assertDefinition(
        FitRecordMeasurementRegistry $registry,
        string $measurementType,
        array $fieldNames,
        string $unit,
    ): void {
        $definition = $registry->scalarDefinition(
            $measurementType,
        );

        self::assertNotNull($definition);

        self::assertSame(
            $fieldNames,
            $definition->fieldNames(),
        );

        self::assertSame(
            $unit,
            $definition->unit->toString(),
        );
    }
}
