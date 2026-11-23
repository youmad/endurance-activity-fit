<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Value;

use Endurance\Activity\Application\Import\ActivityImportWarning;
use Endurance\Activity\Application\Import\ActivityLifecycleItem;

final readonly class FitTerminalFinishResolution
{
    public function __construct(
        public ActivityLifecycleItem $finish,
        public ?ActivityImportWarning $warning = null,
    ) {
    }
}
