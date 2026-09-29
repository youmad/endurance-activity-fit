<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class FitPreStartObservationFilter
{
    private int $skippedCount = 0;

    private ?Instant $earliestSkippedAt = null;

    private ?Instant $latestSkippedAt = null;

    public function __construct(
        private readonly Instant $startedAt,
    ) {
    }

    /**
     * @param iterable<ActivityImportItem> $items
     *
     * @return \Generator<int, ActivityImportItem>
     */
    public function filter(iterable $items): \Generator
    {
        foreach ($items as $sequence => $item) {
            if (
                $item instanceof ObservationItem
                && $item->observation->timestamp->isBefore(
                    $this->startedAt,
                )
            ) {
                $this->recordSkipped($item->observation->timestamp);

                continue;
            }

            yield $sequence => $item;
        }
    }

    public function warning(): ?ActivityImportWarning
    {
        if (
            0 === $this->skippedCount
            || null === $this->earliestSkippedAt
            || null === $this->latestSkippedAt
        ) {
            return null;
        }

        return new ActivityImportWarning(
            code: ActivityImportWarningCode::PreStartObservationsSkipped,
            message: 'FIT observations before the resolved activity start were skipped.',
            context: [
                'skippedCount' => $this->skippedCount,
                'startedAt' => self::formatInstant($this->startedAt),
                'earliestSkippedAt' => self::formatInstant(
                    $this->earliestSkippedAt,
                ),
                'latestSkippedAt' => self::formatInstant(
                    $this->latestSkippedAt,
                ),
                'maximumLeadMicroseconds' => Duration::between(
                    $this->earliestSkippedAt,
                    $this->startedAt,
                )->toMicroseconds(),
            ],
        );
    }

    private function recordSkipped(Instant $timestamp): void
    {
        ++$this->skippedCount;

        if (
            null === $this->earliestSkippedAt
            || $timestamp->isBefore($this->earliestSkippedAt)
        ) {
            $this->earliestSkippedAt = $timestamp;
        }

        if (
            null === $this->latestSkippedAt
            || $timestamp->isAfter($this->latestSkippedAt)
        ) {
            $this->latestSkippedAt = $timestamp;
        }
    }

    private static function formatInstant(Instant $instant): string
    {
        return $instant
            ->toDateTimeImmutable()
            ->format('Y-m-d\\TH:i:s.uP');
    }
}
