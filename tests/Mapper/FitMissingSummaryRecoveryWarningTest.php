<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\ActivitySummaryItem;
use Youmad\Endurance\Activity\Summary\ActivitySummary;
use Youmad\Endurance\ActivityFit\Mapper\FitMissingSummaryRecovery;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class FitMissingSummaryRecoveryWarningTest extends TestCase
{
    public function testReportsRecoveredSessionAndLap(): void
    {
        $first = $this->instant('2026-08-05T10:00:00Z');
        $last = $this->instant('2026-08-05T10:30:00Z');
        $result = (new FitMissingSummaryRecovery())->recover(
            activitySummary: new ActivitySummaryItem(
                ActivitySummary::create(
                    reportedAt: $last,
                    timerDuration: Duration::between($first, $last),
                    sessionCount: 1,
                ),
            ),
            laps: [],
            sessions: [],
            firstObservationAt: $first,
            lastObservationAt: $last,
        );

        self::assertCount(1, $result->sessions);
        self::assertCount(1, $result->laps);
        self::assertSame(
            [
                ActivityImportWarningCode::MissingSessionRecovered,
                ActivityImportWarningCode::MissingLapRecovered,
            ],
            array_map(
                static fn (ActivityImportWarning $warning) => $warning->code,
                $result->warnings,
            ),
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }
}
