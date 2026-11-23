<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Value;

use Endurance\Fit\Unified\UnifiedDataMessage;
use Endurance\Foundation\ValueObject\Duration;
use Endurance\Foundation\ValueObject\Instant;
use Endurance\Foundation\ValueObject\TemporalResolution;

final readonly class FitActivitySummarySource
{
    public function __construct(
        public UnifiedDataMessage $message,
        public Instant $reportedAt,
        public ?Duration $timerDuration,
        public ?int $sessionCount,
        public ?string $type,
        public TemporalResolution $timelineResolution,
        public ?int $localTimeOffsetSeconds = null,
    ) {
    }
}
