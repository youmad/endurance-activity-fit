<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

use Endurance\ActivityFit\Record\FitRecordScalarMeasurementDefinition;

/** @internal */
final readonly class FitFusedRecordScalarOperation
{
    /** @param non-empty-list<FitFusedRecordCandidate> $candidates */
    public function __construct(
        public FitRecordScalarMeasurementDefinition $definition,
        public array $candidates,
    ) {
    }
}
