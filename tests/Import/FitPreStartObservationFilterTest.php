<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Import;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\PositionMeasurement;
use Youmad\Endurance\ActivityFit\Import\FitPreStartObservationFilter;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;
use Youmad\Endurance\Foundation\ValueObject\Instant;

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
