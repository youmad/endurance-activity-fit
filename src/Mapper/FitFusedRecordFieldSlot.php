<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

/** @internal */
final readonly class FitFusedRecordFieldSlot
{
    /** @param list<int> $componentIndexes */
    public function __construct(
        public int $fieldNumber,
        public ?int $physicalIndex,
        public array $componentIndexes,
    ) {
    }
}
