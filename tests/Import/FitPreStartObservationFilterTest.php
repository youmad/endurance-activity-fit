<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Tests\Import;

use Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Endurance\Activity\Application\Import\ObservationItem;
use Endurance\Activity\Telemetry\ActivityObservation;
use Endurance\Activity\Telemetry\PositionMeasurement;
use Endurance\ActivityFit\Import\FitPreStartObservationFilter;
use Endurance\Foundation\ValueObject\Coordinate;
use Endurance\Foundation\ValueObject\Instant;
use PHPUnit\Framework\TestCase;

final class FitPreStartObservationFilterTest extends TestCase
{
    public function testSkipsOnlyObservationsBeforeResolvedStart(): void
    {
        $startedAt = $this->instant('2010-06-25T10:00:00Z');
        $before = $this->observation('2010-06-25T09:56:44Z');
        $atStart = $this->observation('2010-06-25T10:00:00Z');
        $after = $this->observation('2010-06-25T10:00:01Z');
        $filter = new FitPreStartObservationFilter($startedAt);

        $filtered = array_values(iterator_to_array(
            $filter->filter([$before, $atStart, $after]),
        ));

        self::assertSame([$atStart, $after], $filtered);

        $warning = $filter->warning();
        self::assertNotNull($warning);
        self::assertSame(
            ActivityImportWarningCode::PreStartObservationsSkipped,
            $warning->code,
        );
        self::assertSame(
            [
                'skippedCount' => 1,
                'startedAt' => '2010-06-25T10:00:00.000000+00:00',
                'earliestSkippedAt' => '2010-06-25T09:56:44.000000+00:00',
                'latestSkippedAt' => '2010-06-25T09:56:44.000000+00:00',
                'maximumLeadMicroseconds' => 196_000_000,
            ],
            $warning->context,
        );
    }

    public function testDoesNotReportWarningWithoutPreStartObservations(): void
    {
        $startedAt = $this->instant('2010-06-25T10:00:00Z');
        $atStart = $this->observation('2010-06-25T10:00:00Z');
        $filter = new FitPreStartObservationFilter($startedAt);

        self::assertSame(
            [$atStart],
            array_values(iterator_to_array($filter->filter([$atStart]))),
        );
        self::assertNull($filter->warning());
    }

    private function observation(string $timestamp): ObservationItem
    {
        return new ObservationItem(
            ActivityObservation::create(
                $this->instant($timestamp),
                new PositionMeasurement(new Coordinate(59.43, 24.75)),
            ),
        );
    }

    private function instant(string $timestamp): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($timestamp),
        );
    }
}
