<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Value;

use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivitySummaryItem;

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
