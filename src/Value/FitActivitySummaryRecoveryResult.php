<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Value;

use Endurance\Activity\Application\Import\ActivityImportWarning;
use Endurance\Activity\Application\Import\ActivitySummaryItem;

final readonly class FitActivitySummaryRecoveryResult
{
    /**
     * @param list<ActivityImportWarning> $warnings
     */
    public function __construct(
        public ActivitySummaryItem $activitySummary,
        public array $warnings,
    ) {
    }
}
