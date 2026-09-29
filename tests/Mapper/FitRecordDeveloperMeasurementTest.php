<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Telemetry\ArrayMeasurement;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\Telemetry\TextMeasurement;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperMeasurementMetadata;
use Youmad\Endurance\ActivityFit\Mapper\FitRecordMessageMapper;
use Youmad\Endurance\ActivityFit\Tests\Fixture\FitDeveloperFieldFixture;

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
