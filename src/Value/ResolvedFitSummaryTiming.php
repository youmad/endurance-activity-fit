<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Value;

use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final readonly class ResolvedFitSummaryTiming
{
    private function __construct(
        public Instant $startedAt,
        public Instant $finishedAt,
        public Duration $elapsedDuration,
        public Duration $timerDuration,
    ) {
    }

    public static function create(
        Instant $startedAt,
        Instant $finishedAt,
        Duration $elapsedDuration,
        Duration $timerDuration,
    ): self {
        if (
            !Duration::between(
                $startedAt,
                $finishedAt,
            )->equals($elapsedDuration)
        ) {
            throw new \InvalidArgumentException('Resolved FIT summary elapsed duration must match its time boundaries.');
        }

        // Relationships between independently reported durations depend on the
        // message kind. The resolver enforces them where applicable; a Lap may
        // report a timer duration longer than its elapsed duration.

        return new self(
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            elapsedDuration: $elapsedDuration,
            timerDuration: $timerDuration,
        );
    }
}
