<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Value;

use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;

final readonly class FitMissingSummaryRecoveryResult
{
    /**
     * @param list<LapItem>               $laps
     * @param list<SessionItem>           $sessions
     * @param list<ActivityImportWarning> $warnings
     */
    public function __construct(
        public array $laps,
        public array $sessions,
        public array $warnings,
    ) {
    }
}
