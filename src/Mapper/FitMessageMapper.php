<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

interface FitMessageMapper
{
    public function supports(
        UnifiedDataMessage $message,
    ): bool;

    /**
     * One FIT message may describe several domain import items.
     *
     * @return list<ActivityImportItem>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array;
}
