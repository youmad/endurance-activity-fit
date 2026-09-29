<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

final readonly class FitActivityImportItemCounts
{
    public function __construct(
        public int $lifecycleEvents = 0,
        public int $observations = 0,
        public int $laps = 0,
        public int $details = 0,
        public int $sessions = 0,
        public int $devices = 0,
        public int $deviceStatuses = 0,
        public int $activitySummaries = 0,
    ) {
        foreach (
            [
                'lifecycle events' => $lifecycleEvents,
                'observations' => $observations,
                'laps' => $laps,
                'details' => $details,
                'sessions' => $sessions,
                'devices' => $devices,
                'device statuses' => $deviceStatuses,
                'activity summaries' => $activitySummaries,
            ] as $name => $count
        ) {
            if (0 > $count) {
                throw new \InvalidArgumentException(sprintf('FIT activity import %s count cannot be negative.', $name));
            }
        }
    }

    public function total(): int
    {
        return $this->lifecycleEvents
            + $this->observations
            + $this->laps
            + $this->details
            + $this->sessions
            + $this->devices
            + $this->deviceStatuses
            + $this->activitySummaries;
    }
}
