<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Value;

use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

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
