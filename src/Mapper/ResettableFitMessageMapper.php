<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

interface ResettableFitMessageMapper extends FitMessageMapper
{
    public function reset(): void;
}
