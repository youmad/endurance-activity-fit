<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

/** @internal */
final class FitFusedRecordFieldSlotBuilder
{
    /** @var list<int> */
    public array $componentIndexes = [];

    public function __construct(
        public int $fieldNumber,
        public ?int $physicalIndex,
    ) {
    }
}
