<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Youmad\Endurance\Activity\Application\Import\ActivitySummaryItem;
use Youmad\Endurance\Activity\Application\Import\DeviceItem;
use Youmad\Endurance\Activity\Application\Import\DeviceStatusItem;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\ActivityFit\Mapper\FitRecordImportProjection;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

final class FitActivityImportProgress
{
    private int $decodedDataMessages = 0;

    private int $mappedItems = 0;

    private int $lifecycleEvents = 0;

    private int $observations = 0;

    private int $laps = 0;

    private int $details = 0;

    private int $sessions = 0;

    private int $devices = 0;

    private int $deviceStatuses = 0;

    private int $activitySummaries = 0;

    /**
     * @param iterable<UnifiedDataMessage|FitRecordImportProjection> $messages
     *
     * @return \Generator<int, UnifiedDataMessage|FitRecordImportProjection>
     */
    public function trackMessages(
        iterable $messages,
        ?\Closure $onProgress = null,
    ): \Generator {
        foreach ($messages as $sequence => $message) {
            ++$this->decodedDataMessages;

            if (null !== $onProgress) {
                $onProgress();
            }

            yield $sequence => $message;
        }
    }

    /**
     * @param iterable<ActivityImportItem> $items
     *
     * @return \Generator<int, ActivityImportItem>
     */
    public function trackItems(iterable $items): \Generator
    {
        foreach ($items as $sequence => $item) {
            ++$this->mappedItems;
            $this->count($item);

            yield $sequence => $item;
        }
    }

    public function decodedDataMessages(): int
    {
        return $this->decodedDataMessages;
    }

    public function itemCounts(): FitActivityImportItemCounts
    {
        return new FitActivityImportItemCounts(
            lifecycleEvents: $this->lifecycleEvents,
            observations: $this->observations,
            laps: $this->laps,
            details: $this->details,
            sessions: $this->sessions,
            devices: $this->devices,
            deviceStatuses: $this->deviceStatuses,
            activitySummaries: $this->activitySummaries,
        );
    }

    public function mappedItems(): int
    {
        return $this->mappedItems;
    }

    private function count(ActivityImportItem $item): void
    {
        match (true) {
            $item instanceof ActivityLifecycleItem => ++$this->lifecycleEvents,
            $item instanceof ObservationItem => ++$this->observations,
            $item instanceof LapItem => ++$this->laps,
            $item instanceof ActivityDetailItem => ++$this->details,
            $item instanceof SessionItem => ++$this->sessions,
            $item instanceof DeviceItem => ++$this->devices,
            $item instanceof DeviceStatusItem => ++$this->deviceStatuses,
            $item instanceof ActivitySummaryItem => ++$this->activitySummaries,
            default => null,
        };
    }
}
