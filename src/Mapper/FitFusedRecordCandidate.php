<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

/** @internal */
final readonly class FitFusedRecordCandidate
{
    public function __construct(
        public int $fieldIndex,
        public string $fieldName,
        public ?int $additiveFieldIndex,
        public ?string $additiveFieldName,
    ) {
    }
}
