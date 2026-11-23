<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

use Endurance\Activity\Application\Import\ActivityImportItem;
use Endurance\Fit\Unified\UnifiedDataMessage;

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
