<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Youmad\Endurance\Activity\Application\Import\ActivitySummaryItem;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\Activity\Detail\Segment\SegmentEffort;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Import\FitActivityImportItemBuffer;
use Youmad\Endurance\ActivityFit\Import\FitActivityImportWarningCollector;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final readonly class FitActivityImportItemMapper
{
    private const int FILE_ID_GLOBAL_MESSAGE_NUMBER = 0;
    private const int RECORD_GLOBAL_MESSAGE_NUMBER = 20;
    private const int PAD_GLOBAL_MESSAGE_NUMBER = 105;

    /**
     * @var list<FitMessageMapper>
     */
    private array $mappers;

    /**
     * @param array<array-key, mixed> $mappers
     */
    public function __construct(
        array $mappers,
        private FitMissingSummaryRecovery $missingSummaries =
            new FitMissingSummaryRecovery(),
        private FitActivityImportWarningDetector $warningDetector =
            new FitActivityImportWarningDetector(),
        private FitActivityImportWarningCollector $warnings =
            new FitActivityImportWarningCollector(),
        private ?FitActivityMessageMapper $activityMessages = null,
        private FitActivitySummaryRecovery $activitySummaries =
            new FitActivitySummaryRecovery(),
        private FitSummaryAdjacencyValidator $summaryAdjacency =
            new FitSummaryAdjacencyValidator(),
        private ?FitSessionLapValidator $sessionLaps = null,
        private FitTerminalFinishResolver $terminalFinishes = new FitTerminalFinishResolver(),
    ) {
        $mappers = array_values($mappers);
        $validatedMappers = [];

        foreach ($mappers as $mapper) {
            if (!$mapper instanceof FitMessageMapper) {
                throw new \InvalidArgumentException('FIT activity import mappers must implement FitMessageMapper.');
            }

            $validatedMappers[] = $mapper;
        }

        $this->mappers = $validatedMappers;
    }

    public static function standard(): self
    {
        $values = new FitFieldValueReader();
        $devices = new FitDeviceIndexRegistry();
        $descriptors = new FitDeviceDescriptorFactory(
            $values,
        );

        return new self(
            mappers: [
                new FitFileIdMessageMapper(
                    devices: $devices,
                    values: $values,
                    descriptors: $descriptors,
                ),
                new FitTimerEventMessageMapper(
                    values: $values,
                ),
                new FitRecordMessageMapper(
                    values: $values,
                ),
                new FitPoolLengthMessageMapper(
                    values: $values,
                ),
                new FitSegmentLapMessageMapper(
                    values: $values,
                ),
                new FitLapMessageMapper(),
                new FitDeviceInfoMessageMapper(
                    devices: $devices,
                    values: $values,
                    descriptors: $descriptors,
                ),
                new FitSessionMessageMapper(
                    values: $values,
                ),
            ],
            warningDetector: new FitActivityImportWarningDetector(
                values: $values,
            ),
            activityMessages: new FitActivityMessageMapper(
                values: $values,
            ),
            summaryAdjacency: new FitSummaryAdjacencyValidator(
                values: $values,
            ),
            sessionLaps: new FitSessionLapValidator(
                values: $values,
            ),
        );
    }

    /**
     * All matching projections are executed because one FIT message
     * may legitimately produce several domain import items.
     *
     * @return list<ActivityImportItem>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array {
        $this->detectWarnings($message);
        $items = $this->mapRegularMessage($message);

        if (
            null !== $this->activityMessages
            && $this->activityMessages->supports($message)
        ) {
            foreach ($this->activityMessages->map($message) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * FIT permits both Summary First and Summary Last ordering. Interval
     * details, laps, sessions and the activity summary are therefore buffered and
     * emitted after chronological records and timer events. Timer lifecycle
     * events may be serialized before or after records. Spool regular items to
     * a temporary file until all timer events are known, then merge lifecycle
     * events into the original observation order without changing timestamps.
     *
     * A terminal timer event is used only when the file contains no
     * activity summary. When the summary is present, it is authoritative
     * for finalization and validation.
     *
     * @param iterable<UnifiedDataMessage|FitRecordImportProjection> $messages
     * @param bool                                                   $requireGarminSummaryMessages Require the Activity, Session
     *                                                                                             and Lap messages mandated for a complete Garmin Activity file. Keep
     *                                                                                             disabled only when mapping an intentionally partial message stream.
     *
     * @return \Generator<int, ActivityImportItem>
     */
    public function mapStream(
        iterable $messages,
        bool $requireGarminSummaryMessages = false,
    ): \Generator {
        $this->reset();

        /** @var list<ActivityDetailItem> $details */
        $details = [];

        /** @var list<LapItem> $laps */
        $laps = [];

        /** @var list<SessionItem> $sessions */
        $sessions = [];

        $activitySummary = null;
        $activitySummarySource = null;
        $terminalFinish = null;
        $terminalPause = null;
        $firstObservationAt = null;
        $lastObservationAt = null;
        $lastLifecycleAt = null;
        $explicitTimerStartAt = null;
        $firstNonPadGlobalMessageNumber = null;

        /** @var list<ActivityLifecycleItem> $pendingLifecycle */
        $pendingLifecycle = [];
        $pendingLifecycleIndex = 0;
        $deferredItems = new FitActivityImportItemBuffer();

        foreach ($messages as $message) {
            if ($message instanceof FitRecordImportProjection) {
                // A Record still occupies its input position when it maps to no items.
                $firstNonPadGlobalMessageNumber ??= self::RECORD_GLOBAL_MESSAGE_NUMBER;
                $this->sessionLaps?->observeRecordTimestamp($message->timestamp);

                foreach ($message->warnings as $warning) {
                    $this->warnings->add($warning);
                }

                $mappedItems = $message->items;
            } else {
                $globalMessageNumber = $message->globalMessageNumber();

                if (self::PAD_GLOBAL_MESSAGE_NUMBER !== $globalMessageNumber) {
                    $firstNonPadGlobalMessageNumber ??= $globalMessageNumber;
                }

                // Garmin checks this order when a FileId exists. Definition
                // records are absent from this stream; only pad data is ignored.
                if (
                    self::FILE_ID_GLOBAL_MESSAGE_NUMBER === $globalMessageNumber
                    && self::FILE_ID_GLOBAL_MESSAGE_NUMBER !== $firstNonPadGlobalMessageNumber
                ) {
                    throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $message, check: 'FileId Message Is First', detail: sprintf('Expected file_id (global message 0) as the first non-pad data message; found global message %d', $firstNonPadGlobalMessageNumber));
                }

                $this->summaryAdjacency->validate($message);
                $this->sessionLaps?->observe($message);
                $this->detectWarnings($message);

                if (
                    null !== $this->activityMessages
                    && $this->activityMessages->supports($message)
                ) {
                    if (
                        null !== $activitySummarySource
                        || null !== $activitySummary
                    ) {
                        throw InvalidFitActivityMessage::duplicateActivitySummary();
                    }

                    $activitySummarySource = $this->activityMessages->read(
                        $message,
                    );
                }

                $mappedItems = $this->mapRegularMessage($message);
            }

            foreach ($mappedItems as $mappedItemIndex => $item) {
                if ($item instanceof ObservationItem) {
                    $timestamp = $item->observation->timestamp;

                    if (
                        null === $firstObservationAt
                        || $timestamp->isBefore($firstObservationAt)
                    ) {
                        $firstObservationAt = $timestamp;
                    }

                    if (
                        null === $lastObservationAt
                        || $timestamp->isAfter($lastObservationAt)
                    ) {
                        $lastObservationAt = $timestamp;
                    }

                    $deferredItems->append($item);

                    continue;
                }

                if ($item instanceof ActivityDetailItem) {
                    $details[] = $item;

                    continue;
                }

                if ($item instanceof LapItem) {
                    $laps[] = $item;

                    continue;
                }

                if ($item instanceof SessionItem) {
                    $sessions[] = $item;

                    continue;
                }

                if ($item instanceof ActivitySummaryItem) {
                    if (
                        null !== $activitySummary
                        || null !== $activitySummarySource
                    ) {
                        throw InvalidFitActivityMessage::duplicateActivitySummary();
                    }

                    $activitySummary = $item;

                    continue;
                }

                if ($item instanceof ActivityLifecycleItem) {
                    if (
                        (ActivityLifecycleAction::Start === $item->action
                        || ActivityLifecycleAction::TimerStart === $item->action)
                        && null === $explicitTimerStartAt
                    ) {
                        $explicitTimerStartAt = $item->occurredAt;
                    }

                    if (ActivityLifecycleAction::Finish === $item->action) {
                        $terminalFinish = $item;
                        $previousItem = 0 < $mappedItemIndex
                            ? $mappedItems[$mappedItemIndex - 1]
                            : null;
                        $terminalPause = $previousItem
                            instanceof ActivityLifecycleItem
                            && ActivityLifecycleAction::Pause
                                === $previousItem->action
                            && $previousItem->occurredAt->equals(
                                $item->occurredAt,
                            )
                                ? $previousItem
                                : null;

                        continue;
                    }

                    if (
                        null === $lastLifecycleAt
                        || $item->occurredAt->isAfter($lastLifecycleAt)
                    ) {
                        $lastLifecycleAt = $item->occurredAt;
                    }

                    if (
                        (ActivityLifecycleAction::Start === $item->action
                        || ActivityLifecycleAction::TimerStart === $item->action)
                        || ActivityLifecycleAction::Resume === $item->action
                    ) {
                        $terminalFinish = null;
                        $terminalPause = null;
                    }

                    $pendingLifecycle[] = $item;

                    continue;
                }

                $deferredItems->append($item);
            }
        }

        $this->sessionLaps?->validate(
            requireSummaryMessages: $requireGarminSummaryMessages,
        );

        if (
            (
                null !== $activitySummary
                || null !== $activitySummarySource
            )
            && null !== $terminalFinish
            && null !== $terminalPause
        ) {
            $pendingLifecycle = array_values(
                array_filter(
                    $pendingLifecycle,
                    static fn (ActivityLifecycleItem $item): bool => $item !== $terminalPause,
                ),
            );
            $lastLifecycleAt = null;

            foreach ($pendingLifecycle as $item) {
                if (
                    null === $lastLifecycleAt
                    || $item->occurredAt->isAfter($lastLifecycleAt)
                ) {
                    $lastLifecycleAt = $item->occurredAt;
                }
            }
        }

        foreach ($deferredItems->items() as $item) {
            if ($item instanceof ObservationItem) {
                while (
                    isset($pendingLifecycle[$pendingLifecycleIndex])
                    && !$item->observation->timestamp->isBefore(
                        $pendingLifecycle[$pendingLifecycleIndex]->occurredAt,
                    )
                ) {
                    yield $pendingLifecycle[$pendingLifecycleIndex];
                    ++$pendingLifecycleIndex;
                }
            }

            yield $item;
        }

        $deferredItems->close();

        while (isset($pendingLifecycle[$pendingLifecycleIndex])) {
            yield $pendingLifecycle[$pendingLifecycleIndex];
            ++$pendingLifecycleIndex;
        }

        if (null !== $activitySummarySource) {
            $recoveredActivitySummary = $this->activitySummaries->recover(
                source: $activitySummarySource,
                sessions: $sessions,
            );
            $activitySummary = $recoveredActivitySummary->activitySummary;

            foreach ($recoveredActivitySummary->warnings as $warning) {
                $this->warnings->add($warning);
            }
        }

        if (null === $activitySummary && null !== $terminalFinish) {
            $resolvedFinish = $this->terminalFinishes->resolve(
                terminalFinish: $terminalFinish,
                details: $details,
                laps: $laps,
                sessions: $sessions,
                lastObservationAt: $lastObservationAt,
                lastLifecycleAt: $lastLifecycleAt,
            );
            if (null !== $resolvedFinish->warning) {
                $this->warnings->add($resolvedFinish->warning);
            }
            yield $resolvedFinish->finish;
        }

        foreach (
            $this->warningDetector->lapLengthReferenceMismatches(
                details: $details,
                laps: $laps,
            ) as $warning
        ) {
            $this->warnings->add($warning);
        }

        foreach (
            $this->warningDetector->sessionLapReferenceMismatches(
                laps: $laps,
                sessions: $sessions,
            ) as $warning
        ) {
            $this->warnings->add($warning);
        }

        foreach (
            $this->warningDetector->lapActiveLengthCountMismatches(
                details: $details,
                laps: $laps,
            ) as $warning
        ) {
            $this->warnings->add($warning);
        }

        foreach (
            $this->warningDetector->sessionLengthCountMismatches(
                details: $details,
                laps: $laps,
                sessions: $sessions,
            ) as $warning
        ) {
            $this->warnings->add($warning);
        }

        $details = $this->withoutFailedSegmentEffortsOutsideSessions(
            details: $details,
            sessions: $sessions,
        );

        $recovered = $this->missingSummaries->recover(
            activitySummary: $activitySummary,
            laps: $laps,
            sessions: $sessions,
            firstObservationAt: $firstObservationAt,
            lastObservationAt: $lastObservationAt,
            explicitTimerStartAt: $explicitTimerStartAt,
        );
        $laps = $recovered->laps;
        $sessions = $recovered->sessions;

        foreach ($recovered->warnings as $warning) {
            $this->warnings->add($warning);
        }

        foreach (
            $this->warningDetector->summary(
                activitySummary: $activitySummary,
                details: $details,
                laps: $laps,
                sessions: $sessions,
                lastObservationAt: $lastObservationAt,
                lastLifecycleAt: $lastLifecycleAt,
            ) as $warning
        ) {
            $this->warnings->add($warning);
        }

        usort(
            $details,
            static fn (ActivityDetailItem $left, ActivityDetailItem $right): int => self::compareInstants(
                $left->detail->interval()->startedAt,
                $right->detail->interval()->startedAt,
            ),
        );

        foreach (
            $this->warningDetector->activityDetailBoundaryResolutionOverlaps(
                $details,
            ) as $warning
        ) {
            $this->warnings->add($warning);
        }

        foreach ($details as $detail) {
            yield $detail;
        }

        usort(
            $laps,
            static fn (LapItem $left, LapItem $right): int => self::compareInstants(
                $left->lap->startedAt,
                $right->lap->startedAt,
            ),
        );

        foreach (
            $this->warningDetector->lapBoundaryResolutionOverlaps($laps) as $warning
        ) {
            $this->warnings->add($warning);
        }

        foreach ($laps as $lap) {
            yield $lap;
        }

        usort(
            $sessions,
            static fn (SessionItem $left, SessionItem $right): int => self::compareInstants(
                $left->session->startedAt,
                $right->session->startedAt,
            ),
        );

        foreach (
            $this->warningDetector->sessionBoundaryResolutionOverlaps($sessions) as $warning
        ) {
            $this->warnings->add($warning);
        }

        foreach ($sessions as $session) {
            yield $session;
        }

        if (null !== $activitySummary) {
            yield $activitySummary;
        }
    }

    /**
     * A failed Segment Lap is an optional attempted-segment annotation, not a
     * boundary used by Garmin's Activity validator. Keep failed attempts that
     * belong to the Activity, but do not let an attempt outside the envelope
     * of explicit Session summaries enlarge or invalidate the Activity.
     *
     * @param list<ActivityDetailItem> $details
     * @param list<SessionItem>        $sessions
     *
     * @return list<ActivityDetailItem>
     */
    private function withoutFailedSegmentEffortsOutsideSessions(
        array $details,
        array $sessions,
    ): array {
        if ([] === $sessions) {
            return $details;
        }

        $activityStartedAt = $sessions[0]->session->startedAt;
        $activityFinishedAt = $sessions[0]->session->finishedAt;

        foreach ($sessions as $session) {
            if (
                $session->session->startedAt->isBefore(
                    $activityStartedAt,
                )
            ) {
                $activityStartedAt = $session->session->startedAt;
            }

            if (
                $session->session->finishedAt->isAfter(
                    $activityFinishedAt,
                )
            ) {
                $activityFinishedAt = $session->session->finishedAt;
            }
        }

        $kept = [];

        foreach ($details as $item) {
            $detail = $item->detail;

            if (
                !$detail instanceof SegmentEffort
                || 'fail' !== $detail->status
            ) {
                $kept[] = $item;

                continue;
            }

            $interval = $detail->interval();

            if (
                !$interval->startedAt->isBefore($activityStartedAt)
                && !$interval->finishedAt->isAfter($activityFinishedAt)
            ) {
                $kept[] = $item;

                continue;
            }

            $this->warnings->add(
                new ActivityImportWarning(
                    code: ActivityImportWarningCode::FailedSegmentEffortOutsideSessionSkipped,
                    message: 'Failed FIT segment effort outside the explicit Session envelope was skipped.',
                    context: [
                        'segmentId' => $detail->segmentId,
                        'segmentName' => $detail->name,
                        'segmentStartedAt' => self::formatInstant(
                            $interval->startedAt,
                        ),
                        'segmentFinishedAt' => self::formatInstant(
                            $interval->finishedAt,
                        ),
                        'sessionStartedAt' => self::formatInstant(
                            $activityStartedAt,
                        ),
                        'sessionFinishedAt' => self::formatInstant(
                            $activityFinishedAt,
                        ),
                    ],
                ),
            );
        }

        return $kept;
    }

    private function detectWarnings(
        UnifiedDataMessage $message,
    ): void {
        foreach (
            $this->warningDetector->messageWarnings($message) as $warning
        ) {
            $this->warnings->add($warning);
        }
    }

    /** @return list<ActivityImportItem> */
    private function mapRegularMessage(
        UnifiedDataMessage $message,
    ): array {
        $items = [];

        foreach ($this->mappers as $mapper) {
            if (!$mapper->supports($message)) {
                continue;
            }

            foreach ($mapper->map($message) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function reportWarning(ActivityImportWarning $warning): void
    {
        $this->warnings->add($warning);
    }

    /** @return list<ActivityImportWarning> */
    public function warnings(): array
    {
        return $this->warnings->all();
    }

    public function reset(): void
    {
        $this->warnings->reset();
        $this->summaryAdjacency->reset();
        $this->sessionLaps?->reset();

        foreach ($this->mappers as $mapper) {
            if ($mapper instanceof ResettableFitMessageMapper) {
                $mapper->reset();
            }
        }
    }

    private static function formatInstant(Instant $instant): string
    {
        return $instant
            ->toDateTimeImmutable()
            ->format('Y-m-d\TH:i:s.uP');
    }

    private static function compareInstants(
        Instant $left,
        Instant $right,
    ): int {
        if ($left->equals($right)) {
            return 0;
        }

        return $left->isBefore($right)
            ? -1
            : 1;
    }
}
