<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Value;

use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleItem;

final readonly class FitTerminalFinishResolution
{
    public function __construct(
        public ActivityLifecycleItem $finish,
        public ?ActivityImportWarning $warning = null,
    ) {
    }
}
