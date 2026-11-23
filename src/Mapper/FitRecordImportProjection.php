<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

use Endurance\Activity\Application\Import\ActivityImportItem;
use Endurance\Activity\Application\Import\ActivityImportWarning;

/**
 * Pre-mapped Record result used by the optimized FIT import stream.
 */
final readonly class FitRecordImportProjection
{
    /**
     * @param list<ActivityImportItem>    $items
     * @param list<ActivityImportWarning> $warnings
     */
    public function __construct(
        public array $items,
        public array $warnings = [],
        /** Source Record timestamp in FIT seconds, including Records without items. */
        public ?int $timestamp = null,
    ) {
    }
}
