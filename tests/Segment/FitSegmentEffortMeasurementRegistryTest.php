<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Segment;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityFit\Segment\FitSegmentEffortMeasurementRegistry;
use Youmad\Endurance\ActivityFit\Segment\FitSegmentEffortScalarMeasurementDefinition;

final class FitSegmentEffortMeasurementRegistryTest extends TestCase
{
    public function testStandardRegistryContainsRepresentativeSegmentMetrics(): void
    {
        $definitions = (new FitSegmentEffortMeasurementRegistry())
            ->scalarDefinitions();

        $byType = [];

        foreach ($definitions as $definition) {
            $byType[$definition->measurementType->toString()]
                = $definition;
        }

        self::assertCount(50, $definitions);
        self::assertSame(
            ['total_distance'],
            $byType['total_distance']->fieldNames(),
        );
        self::assertSame(
            ['total_strokes'],
            $byType['total_strokes']->fieldNames(),
        );
        self::assertSame('strokes', $byType['total_strokes']->unit->toString());
        self::assertSame(
            ['total_cycles'],
            $byType['total_cycles']->fieldNames(),
        );
        self::assertSame('cycles', $byType['total_cycles']->unit->toString());
        self::assertSame(
            ['enhanced_avg_altitude', 'avg_altitude'],
            $byType['average_altitude']->fieldNames(),
        );
        self::assertSame(
            ['avg_grit'],
            $byType['average_grit']->fieldNames(),
        );
        self::assertSame(
            ['avg_flow'],
            $byType['average_flow']->fieldNames(),
        );
    }

    public function testRejectsDuplicateMeasurementTypes(): void
    {
        $definition = FitSegmentEffortScalarMeasurementDefinition::create(
            measurementType: 'total_distance',
            fieldNames: ['total_distance'],
            unit: 'm',
        );

        $this->expectException(
            \InvalidArgumentException::class,
        );

        new FitSegmentEffortMeasurementRegistry([
            $definition,
            $definition,
        ]);
    }
}
