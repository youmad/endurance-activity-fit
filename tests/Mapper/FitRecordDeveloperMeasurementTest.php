<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Tests\Mapper;

use Endurance\Activity\Application\Import\ObservationItem;
use Endurance\Activity\Telemetry\ArrayMeasurement;
use Endurance\Activity\Telemetry\ScalarMeasurement;
use Endurance\Activity\Telemetry\TextMeasurement;
use Endurance\ActivityFit\Developer\FitDeveloperMeasurementMetadata;
use Endurance\ActivityFit\Mapper\FitRecordMessageMapper;
use Endurance\ActivityFit\Tests\Fixture\FitDeveloperFieldFixture;
use PHPUnit\Framework\TestCase;

final class FitRecordDeveloperMeasurementTest extends TestCase
{
    public function testCreatesObservationFromDeveloperTelemetryOnly(): void
    {
        $items = (new FitRecordMessageMapper())->map(
            (new FitDeveloperFieldFixture())->completeRecord(),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(
            ObservationItem::class,
            $items[0],
        );

        $readings = $items[0]
            ->observation
            ->readings();

        self::assertCount(3, $readings);
        self::assertInstanceOf(
            ScalarMeasurement::class,
            $readings[0]->measurement,
        );
        self::assertInstanceOf(
            TextMeasurement::class,
            $readings[1]->measurement,
        );
        self::assertInstanceOf(
            ArrayMeasurement::class,
            $readings[2]->measurement,
        );
        self::assertInstanceOf(
            FitDeveloperMeasurementMetadata::class,
            $readings[0]->metadata,
        );
    }
}
