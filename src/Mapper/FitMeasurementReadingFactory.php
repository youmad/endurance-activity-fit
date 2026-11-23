<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

use Endurance\Activity\Telemetry\Measurement;
use Endurance\Activity\Telemetry\MeasurementReading;
use Endurance\Activity\Telemetry\MeasurementSource;
use Endurance\Fit\Unified\FieldValueOrigin;

final readonly class FitMeasurementReadingFactory
{
    public function create(
        Measurement $measurement,
        FieldValueOrigin ...$origins,
    ): MeasurementReading {
        if ([] === $origins) {
            throw new \InvalidArgumentException('FIT measurement reading requires at least one source origin.');
        }

        $source = MeasurementSource::unknown();

        if (
            in_array(
                FieldValueOrigin::Component,
                $origins,
                true,
            )
        ) {
            return MeasurementReading::derived(
                measurement: $measurement,
                source: $source,
            );
        }

        return MeasurementReading::reported(
            measurement: $measurement,
            source: $source,
        );
    }
}
