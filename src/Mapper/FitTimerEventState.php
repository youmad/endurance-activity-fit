<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

use Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Endurance\ActivityFit\Value\FitTimerState;
use Endurance\Fit\Unified\UnifiedDataMessage;
use Endurance\Foundation\ValueObject\Instant;

final class FitTimerEventState
{
    private FitTimerState $state = FitTimerState::AwaitingStart;

    private ?Instant $lastEventAt = null;

    public function state(): FitTimerState
    {
        return $this->state;
    }

    public function moveTo(
        FitTimerState $state,
        Instant $occurredAt,
        UnifiedDataMessage $message,
    ): void {
        if (
            null !== $this->lastEventAt
            && $occurredAt->isBefore(
                $this->lastEventAt,
            )
        ) {
            throw InvalidFitActivityMessage::timerEventOutOfOrder(message: $message, occurredAt: $occurredAt->toDateTimeImmutable(), previousAt: $this->lastEventAt->toDateTimeImmutable());
        }

        $this->state = $state;
        $this->lastEventAt = $occurredAt;
    }

    public function reset(): void
    {
        $this->state = FitTimerState::AwaitingStart;
        $this->lastEventAt = null;
    }
}
