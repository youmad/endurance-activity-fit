<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Tests\PoolSwimming;

use Endurance\ActivityFit\PoolSwimming\FitPoolLengthMeasurementRegistry;
use PHPUnit\Framework\TestCase;

final class FitPoolLengthMeasurementRegistryTest extends TestCase
{
    public function testStandardRegistrySeparatesActiveOnlyMetrics(): void
    {
        $definitions = (new FitPoolLengthMeasurementRegistry())
            ->scalarDefinitions();

        $byType = [];

        foreach ($definitions as $definition) {
            $byType[
                $definition->measurementType->toString()
            ] = $definition;
        }

        self::assertTrue(
            $byType['stroke_count']->activeOnly,
        );

        self::assertTrue(
            $byType['average_speed']->activeOnly,
        );

        self::assertFalse(
            $byType['total_calories']->activeOnly,
        );

        self::assertSame(
            [
                'enhanced_avg_respiration_rate',
                'avg_respiration_rate',
            ],
            $byType['average_respiration_rate']
                ->fieldNames(),
        );
    }
}
