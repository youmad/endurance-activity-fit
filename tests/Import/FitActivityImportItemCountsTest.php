<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Tests\Import;

use Endurance\ActivityFit\Import\FitActivityImportItemCounts;
use PHPUnit\Framework\TestCase;

final class FitActivityImportItemCountsTest extends TestCase
{
    public function testCalculatesTotal(): void
    {
        $counts = new FitActivityImportItemCounts(
            lifecycleEvents: 4,
            observations: 100,
            laps: 5,
            details: 2,
            sessions: 1,
            devices: 2,
            deviceStatuses: 3,
            activitySummaries: 1,
        );

        self::assertSame(118, $counts->total());
    }

    public function testRejectsNegativeCount(): void
    {
        $this->expectException(
            \InvalidArgumentException::class,
        );

        new FitActivityImportItemCounts(
            observations: -1,
        );
    }
}
