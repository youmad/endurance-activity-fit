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

    public function observe(ActivityImportItem $item): void
    {
        if (
            $item instanceof ActivityLifecycleItem
            && ActivityLifecycleAction::Start === $item->action
        ) {
            $this->explicitStart = $item->occurredAt;
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
        return $this->explicitStart
            ?? $this->earliest
            ?? throw FitActivityStartNotFound::inFile();
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
