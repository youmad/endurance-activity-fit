<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Youmad\Endurance\Activity\Application\Import\DeviceStatusItem;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\ActivityFit\Exception\FitActivityStartNotFound;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class FitActivityStartResolver
{
    private ?Instant $explicitStart = null;

    private ?Instant $earliest = null;

    private ?Instant $earliestSessionStart = null;

    public function observe(ActivityImportItem $item): void
    {
        if (
            $item instanceof ActivityLifecycleItem
            && (
                ActivityLifecycleAction::Start === $item->action
                || ActivityLifecycleAction::TimerStart === $item->action
            )
        ) {
            $this->explicitStart = $item->occurredAt;
        }

        if (
            $item instanceof SessionItem
            && (
                null === $this->earliestSessionStart
                || $item->session->startedAt->isBefore($this->earliestSessionStart)
            )
        ) {
            $this->earliestSessionStart = $item->session->startedAt;
        }

        $candidate = self::candidate($item);

        if (
            null !== $candidate
            && (
                null === $this->earliest
                || $candidate->isBefore($this->earliest)
            )
        ) {
            $this->earliest = $candidate;
        }
    }

    public function resolve(): Instant
    {
        $start = $this->explicitStart
            ?? $this->earliest
            ?? throw FitActivityStartNotFound::inFile();

        // Session intervals may include time before the first timer event.
        // Do not use an arbitrary early Record or Lap to widen this interval.
        if (
            null !== $this->earliestSessionStart
            && $this->earliestSessionStart->isBefore($start)
        ) {
            return $this->earliestSessionStart;
        }

        return $start;
    }

    private static function candidate(
        ActivityImportItem $item,
    ): ?Instant {
        return match (true) {
            $item instanceof ActivityLifecycleItem => $item->occurredAt,
            $item instanceof ObservationItem => $item->observation->timestamp,
            $item instanceof DeviceStatusItem => $item->observation->observedAt,
            $item instanceof ActivityDetailItem => $item->detail->interval()->startedAt,
            $item instanceof LapItem => $item->lap->startedAt,
            $item instanceof SessionItem => $item->session->startedAt,
            default => null,
        };
    }
}
