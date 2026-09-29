<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\ActivitySummaryItem;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\Activity\Detail\Pool\PoolLength;
use Youmad\Endurance\Activity\Detail\Pool\PoolLengthType;
use Youmad\Endurance\Activity\Detail\SequentialActivityDetail;
use Youmad\Endurance\ActivityFit\Conversion\FitDurationConverter;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final readonly class FitActivityImportWarningDetector
{
    private const int LAP_GLOBAL_MESSAGE_NUMBER = 19;
    private const int RECORD_GLOBAL_MESSAGE_NUMBER = 20;
    private const int DEVICE_INFO_GLOBAL_MESSAGE_NUMBER = 23;
    private const int SESSION_GLOBAL_MESSAGE_NUMBER = 18;
    private const int ACTIVITY_GLOBAL_MESSAGE_NUMBER = 34;
    private const int SEGMENT_LAP_GLOBAL_MESSAGE_NUMBER = 142;

    /** @var array<int, string> */
    private const array SUMMARY_MESSAGE_NAMES = [
        18 => 'session',
        19 => 'lap',
        101 => 'length',
        142 => 'segment_lap',
    ];

    public function __construct(
        private FitFieldValueReader $values = new FitFieldValueReader(),
        private FitDurationConverter $durations = new FitDurationConverter(),
    ) {
    }

    /** @return list<ActivityImportWarning> */
    public function messageWarnings(
        UnifiedDataMessage $message,
    ): array {
        $warnings = match ($message->globalMessageNumber()) {
            self::RECORD_GLOBAL_MESSAGE_NUMBER => [
                $this->missingRecordTimestamp($message),
                $this->recordPartialPosition($message),
            ],
            self::DEVICE_INFO_GLOBAL_MESSAGE_NUMBER => [
                $this->deviceInfoWithoutIndex($message),
            ],
            self::SESSION_GLOBAL_MESSAGE_NUMBER => [
                $this->sessionEvent($message),
                $this->sessionPartialStartPosition($message),
                $this->sessionPartialEndPosition($message),
                $this->summaryTimerDurationResolutionAdjustment($message),
            ],
            self::LAP_GLOBAL_MESSAGE_NUMBER => [
                $this->lapTimerDurationMismatch($message),
            ],
            101 => [
                $this->summaryTimerDurationResolutionAdjustment($message),
            ],
            self::SEGMENT_LAP_GLOBAL_MESSAGE_NUMBER => [
                $this->segmentPartialStartPosition($message),
                $this->segmentPartialEndPosition($message),
                $this->summaryTimerDurationResolutionAdjustment($message),
            ],
            self::ACTIVITY_GLOBAL_MESSAGE_NUMBER => [
                $this->activityEvent($message),
            ],
            default => [],
        };

        return array_values(
            array_filter(
                $warnings,
                static fn (?ActivityImportWarning $warning): bool => null !== $warning,
            ),
        );
    }

    public function missingRecordTimestamp(
        UnifiedDataMessage $message,
    ): ?ActivityImportWarning {
        if (
            self::RECORD_GLOBAL_MESSAGE_NUMBER
            !== $message->globalMessageNumber()
        ) {
            return null;
        }

        if (
            null !== $this->values->byName(
                message: $message,
                fieldName: 'timestamp',
            )
        ) {
            return null;
        }

        return new ActivityImportWarning(
            code: ActivityImportWarningCode::RecordWithoutTimestampSkipped,
            message: 'FIT record without a usable timestamp was skipped.',
            context: [
                'sequence' => $message->sequence(),
                'byteOffset' => $message->byteOffset(),
            ],
        );
    }

    public function recordPartialPosition(
        UnifiedDataMessage $message,
    ): ?ActivityImportWarning {
        if (
            self::RECORD_GLOBAL_MESSAGE_NUMBER
            !== $message->globalMessageNumber()
        ) {
            return null;
        }

        try {
            $latitude = $this->values->byName(
                message: $message,
                fieldName: 'position_lat',
            );
            $longitude = $this->values->byName(
                message: $message,
                fieldName: 'position_long',
            );
        } catch (InvalidFitActivityMessage) {
            // The mapper remains responsible for ambiguous field selection.
            return null;
        }

        if ((null === $latitude) === (null === $longitude)) {
            return null;
        }

        return new ActivityImportWarning(
            code: ActivityImportWarningCode::RecordCoordinatePairSkipped,
            message: 'Optional FIT record coordinate pair was ignored because only one coordinate field resolved to a scalar value.',
            context: [
                'sequence' => $message->sequence(),
                'byteOffset' => $message->byteOffset(),
                'latitudeField' => 'position_lat',
                'longitudeField' => 'position_long',
                'resolvedField' => null === $latitude
                    ? 'position_long'
                    : 'position_lat',
                'unresolvedField' => null === $latitude
                    ? 'position_lat'
                    : 'position_long',
            ],
        );
    }

    public function deviceInfoWithoutIndex(
        UnifiedDataMessage $message,
    ): ?ActivityImportWarning {
        if (
            self::DEVICE_INFO_GLOBAL_MESSAGE_NUMBER
            !== $message->globalMessageNumber()
        ) {
            return null;
        }

        try {
            $deviceIndex = $this->values->byName(
                message: $message,
                fieldName: 'device_index',
            );
        } catch (InvalidFitActivityMessage) {
            // The mapper remains responsible for malformed usable values.
            return null;
        }

        if (null !== $deviceIndex) {
            return null;
        }

        return new ActivityImportWarning(
            code: ActivityImportWarningCode::DeviceInfoWithoutIndexSkipped,
            message: 'FIT device_info without a usable device_index was skipped.',
            context: [
                'sequence' => $message->sequence(),
                'byteOffset' => $message->byteOffset(),
            ],
        );
    }

    public function lapTimerDurationMismatch(
        UnifiedDataMessage $message,
    ): ?ActivityImportWarning {
        if (self::LAP_GLOBAL_MESSAGE_NUMBER !== $message->globalMessageNumber()) {
            return null;
        }

        try {
            $elapsed = $this->values->byName($message, 'total_elapsed_time');
            $timer = $this->values->byName($message, 'total_timer_time');

            if (null === $elapsed || null === $timer) {
                return null;
            }

            $elapsedDuration = $this->durations->convert($elapsed);
            $timerDuration = $this->durations->convert($timer);
        } catch (InvalidFitActivityMessage) {
            return null;
        }

        if (!$timerDuration->isLongerThan($elapsedDuration)) {
            return null;
        }

        return new ActivityImportWarning(
            code: ActivityImportWarningCode::LapTimerDurationExceedsElapsed,
            message: 'FIT Lap timer duration exceeds its elapsed duration; both source values and the elapsed time boundary were preserved.',
            context: [
                'messageName' => 'lap',
                'sequence' => $message->sequence(),
                'byteOffset' => $message->byteOffset(),
                'elapsedMicroseconds' => $elapsedDuration->toMicroseconds(),
                'timerMicroseconds' => $timerDuration->toMicroseconds(),
                'excessMicroseconds' => $timerDuration->minus($elapsedDuration)->toMicroseconds(),
            ],
        );
    }

    public function summaryTimerDurationResolutionAdjustment(
        UnifiedDataMessage $message,
    ): ?ActivityImportWarning {
        if (self::LAP_GLOBAL_MESSAGE_NUMBER === $message->globalMessageNumber()) {
            return null;
        }

        $messageName = self::SUMMARY_MESSAGE_NAMES[
            $message->globalMessageNumber()
        ] ?? null;

        if (null === $messageName) {
            return null;
        }

        try {
            $elapsed = $this->values->byName(
                message: $message,
                fieldName: 'total_elapsed_time',
            );
            $timer = $this->values->byName(
                message: $message,
                fieldName: 'total_timer_time',
            );

            if (null === $elapsed || null === $timer) {
                return null;
            }

            $elapsedDuration = $this->durations->convert($elapsed);
            $timerDuration = $this->durations->convert($timer);
        } catch (InvalidFitActivityMessage) {
            // The mapper remains responsible for reporting malformed fields.
            return null;
        }

        if (!$timerDuration->isLongerThan($elapsedDuration)) {
            return null;
        }

        $adjustmentMicroseconds = $timerDuration
            ->minus($elapsedDuration)
            ->toMicroseconds();

        if (
            $adjustmentMicroseconds
            >= TemporalResolution::Second->value
        ) {
            return null;
        }

        return new ActivityImportWarning(
            code: ActivityImportWarningCode::SummaryTimerDurationResolutionAdjusted,
            message: 'FIT summary elapsed duration was extended to contain a longer timer duration within the source timestamp resolution.',
            context: [
                'messageName' => $messageName,
                'sequence' => $message->sequence(),
                'byteOffset' => $message->byteOffset(),
                'elapsedMicroseconds' => $elapsedDuration->toMicroseconds(),
                'timerMicroseconds' => $timerDuration->toMicroseconds(),
                'adjustmentMicroseconds' => $adjustmentMicroseconds,
                'resolutionMicroseconds' => TemporalResolution::Second->value,
            ],
        );
    }

    public function sessionEvent(
        UnifiedDataMessage $message,
    ): ?ActivityImportWarning {
        if (
            self::SESSION_GLOBAL_MESSAGE_NUMBER
            !== $message->globalMessageNumber()
        ) {
            return null;
        }

        try {
            $event = $this->values->byName($message, 'event');
            $eventType = $this->values->byName(
                $message,
                'event_type',
            );
        } catch (InvalidFitActivityMessage) {
            return new ActivityImportWarning(
                code: ActivityImportWarningCode::UnknownSessionEvent,
                message: 'Optional session event fields could not be interpreted and were ignored.',
                context: [
                    'reason' => 'ambiguous_optional_fields',
                ],
            );
        }

        if (null === $event && null === $eventType) {
            return null;
        }

        $eventName = $event?->symbolicName();
        $eventTypeName = $eventType?->symbolicName();
        $knownEvent = null === $event
            || 'session' === $eventName;
        $knownType = null === $eventType
            || 'stop' === $eventTypeName;

        if ($knownEvent && $knownType) {
            return null;
        }

        return new ActivityImportWarning(
            code: ActivityImportWarningCode::UnknownSessionEvent,
            message: 'Non-canonical optional session event fields were ignored.',
            context: [
                'event' => $event?->value,
                'eventName' => $eventName,
                'eventType' => $eventType?->value,
                'eventTypeName' => $eventTypeName,
            ],
        );
    }

    public function sessionPartialStartPosition(
        UnifiedDataMessage $message,
    ): ?ActivityImportWarning {
        return $this->sessionPartialCoordinatePair(
            message: $message,
            latitudeField: 'start_position_lat',
            longitudeField: 'start_position_long',
        );
    }

    public function sessionPartialEndPosition(
        UnifiedDataMessage $message,
    ): ?ActivityImportWarning {
        return $this->sessionPartialCoordinatePair(
            message: $message,
            latitudeField: 'end_position_lat',
            longitudeField: 'end_position_long',
        );
    }

    public function segmentPartialStartPosition(
        UnifiedDataMessage $message,
    ): ?ActivityImportWarning {
        return $this->segmentPartialCoordinatePair(
            message: $message,
            latitudeField: 'start_position_lat',
            longitudeField: 'start_position_long',
        );
    }

    public function segmentPartialEndPosition(
        UnifiedDataMessage $message,
    ): ?ActivityImportWarning {
        return $this->segmentPartialCoordinatePair(
            message: $message,
            latitudeField: 'end_position_lat',
            longitudeField: 'end_position_long',
        );
    }

    public function activityEvent(
        UnifiedDataMessage $message,
    ): ?ActivityImportWarning {
        if (
            self::ACTIVITY_GLOBAL_MESSAGE_NUMBER
            !== $message->globalMessageNumber()
        ) {
            return null;
        }

        try {
            $event = $this->values->byName($message, 'event');
            $eventType = $this->values->byName(
                $message,
                'event_type',
            );
        } catch (InvalidFitActivityMessage) {
            return new ActivityImportWarning(
                code: ActivityImportWarningCode::UnknownActivityEvent,
                message: 'Optional activity event fields could not be interpreted and were ignored.',
                context: [
                    'reason' => 'ambiguous_optional_fields',
                ],
            );
        }

        // Unlike Session, the FIT Profile does not constrain the
        // values of Activity.event or Activity.event_type. Treat any
        // successfully decoded values as optional metadata rather than
        // inventing a narrower canonical pair here.
        return null;
    }

    /**
     * @param list<ActivityDetailItem> $details
     * @param list<LapItem>            $laps
     * @param list<SessionItem>        $sessions
     *
     * @return list<ActivityImportWarning>
     */
    public function summary(
        ?ActivitySummaryItem $activitySummary,
        array $details,
        array $laps,
        array $sessions,
        ?Instant $lastObservationAt,
        ?Instant $lastLifecycleAt,
    ): array {
        if (null === $activitySummary || [] === $sessions) {
            return [];
        }

        $warnings = [];
        $timerMismatch = $this->timerMismatch(
            activitySummary: $activitySummary,
            sessions: $sessions,
        );

        if (null !== $timerMismatch) {
            $warnings[] = $timerMismatch;
        }

        $boundaryAdjustment = $this->boundaryAdjustment(
            activitySummary: $activitySummary,
            details: $details,
            laps: $laps,
            sessions: $sessions,
            lastObservationAt: $lastObservationAt,
            lastLifecycleAt: $lastLifecycleAt,
        );

        if (null !== $boundaryAdjustment) {
            $warnings[] = $boundaryAdjustment;
        }

        return $warnings;
    }

    /**
     * @param list<ActivityDetailItem> $details
     *
     * @return list<ActivityImportWarning>
     */
    public function activityDetailBoundaryResolutionOverlaps(
        array $details,
    ): array {
        $warnings = [];
        $previousFinishedAt = [];

        foreach ($details as $item) {
            $detail = $item->detail;

            if (!$detail instanceof SequentialActivityDetail) {
                continue;
            }

            $sequenceName = $detail->sequenceName();
            $interval = $detail->interval();
            $previous = $previousFinishedAt[$sequenceName] ?? null;

            if (
                null !== $previous
                && $interval->startedAt->isBefore($previous)
            ) {
                $overlapMicroseconds = Duration::between(
                    $interval->startedAt,
                    $previous,
                )->toMicroseconds();

                if (
                    $overlapMicroseconds
                    < $detail->timelineResolution()->value
                ) {
                    $warnings[] = new ActivityImportWarning(
                        code: ActivityImportWarningCode::ActivityDetailBoundaryResolutionOverlap,
                        message: 'Adjacent activity detail boundaries overlap within the declared source timestamp resolution; source durations were preserved.',
                        context: [
                            'sequenceName' => $sequenceName,
                            'previousFinishedAt' => self::formatInstant(
                                $previous,
                            ),
                            'startedAt' => self::formatInstant(
                                $interval->startedAt,
                            ),
                            'overlapMicroseconds' => $overlapMicroseconds,
                            'resolutionMicroseconds' => $detail->timelineResolution()->value,
                        ],
                    );
                }
            }

            if (
                null === $previous
                || $interval->finishedAt->isAfter($previous)
            ) {
                $previousFinishedAt[$sequenceName] = $interval->finishedAt;
            }
        }

        return $warnings;
    }

    /**
     * @param list<LapItem> $laps
     *
     * @return list<ActivityImportWarning>
     */
    public function lapBoundaryResolutionOverlaps(
        array $laps,
    ): array {
        $warnings = [];
        $previous = null;

        foreach ($laps as $item) {
            $lap = $item->lap;

            if (
                null !== $previous
                && $lap->startedAt->isBefore(
                    $previous->finishedAt,
                )
            ) {
                $overlapMicroseconds = Duration::between(
                    $lap->startedAt,
                    $previous->finishedAt,
                )->toMicroseconds();

                if (
                    $overlapMicroseconds
                    < $lap->timelineResolution->value
                ) {
                    $warnings[] = new ActivityImportWarning(
                        code: ActivityImportWarningCode::LapBoundaryResolutionOverlap,
                        message: 'Adjacent lap boundaries overlap within the declared source timestamp resolution; source durations were preserved.',
                        context: [
                            'previousFinishedAt' => self::formatInstant(
                                $previous->finishedAt,
                            ),
                            'startedAt' => self::formatInstant(
                                $lap->startedAt,
                            ),
                            'overlapMicroseconds' => $overlapMicroseconds,
                            'resolutionMicroseconds' => $lap->timelineResolution->value,
                        ],
                    );
                }
            }

            $previous = $lap;
        }

        return $warnings;
    }

    /**
     * Validate only explicit, fully verifiable FIT Lap -> Length references.
     * If any pool length has no message_index, an apparently missing index may
     * belong to that unindexed length and therefore is not a proven mismatch.
     *
     * @param list<ActivityDetailItem> $details
     * @param list<LapItem>            $laps
     *
     * @return list<ActivityImportWarning>
     */
    public function lapLengthReferenceMismatches(
        array $details,
        array $laps,
    ): array {
        $availableLengthIndexes = [];

        foreach ($details as $detail) {
            if (!$detail->detail instanceof PoolLength) {
                continue;
            }

            if (null === $detail->index) {
                return [];
            }

            $availableLengthIndexes[$detail->index] = true;
        }

        $warnings = [];

        foreach ($laps as $lap) {
            if (
                null === $lap->firstLengthIndex
                || null === $lap->lengthCount
                || 0 === $lap->lengthCount
            ) {
                continue;
            }

            $firstMissingLengthIndex = null;
            $missingLengthCount = 0;
            $lastLengthIndex = $lap->firstLengthIndex
                + $lap->lengthCount - 1;

            for (
                $lengthIndex = $lap->firstLengthIndex;
                $lengthIndex <= $lastLengthIndex;
                ++$lengthIndex
            ) {
                if (!isset($availableLengthIndexes[$lengthIndex])) {
                    $firstMissingLengthIndex ??= $lengthIndex;
                    ++$missingLengthCount;
                }
            }

            if (0 === $missingLengthCount) {
                continue;
            }

            $warnings[] = new ActivityImportWarning(
                code: ActivityImportWarningCode::LapLengthReferenceMismatch,
                message: 'FIT lap references pool length indexes that are not present in the fully indexed length summaries.',
                context: [
                    'firstLengthIndex' => $lap->firstLengthIndex,
                    'lengthCount' => $lap->lengthCount,
                    'firstMissingLengthIndex' => $firstMissingLengthIndex,
                    'missingLengthCount' => $missingLengthCount,
                ],
            );
        }

        return $warnings;
    }

    /**
     * Compare the declared number of active pool lengths with the fully
     * indexed Length messages referenced by each Lap. If any pool length is
     * unindexed, or the Lap reference range is incomplete, the count cannot
     * be proven and no warning is emitted.
     *
     * @param list<ActivityDetailItem> $details
     * @param list<LapItem>            $laps
     *
     * @return list<ActivityImportWarning>
     */
    public function lapActiveLengthCountMismatches(
        array $details,
        array $laps,
    ): array {
        $lengthsByIndex = [];

        foreach ($details as $detail) {
            if (!$detail->detail instanceof PoolLength) {
                continue;
            }

            if (
                null === $detail->index
                || isset($lengthsByIndex[$detail->index])
            ) {
                return [];
            }

            $lengthsByIndex[$detail->index] = $detail->detail;
        }

        $warnings = [];

        foreach ($laps as $lap) {
            if (
                null === $lap->activeLengthCount
                || null === $lap->firstLengthIndex
                || null === $lap->lengthCount
            ) {
                continue;
            }

            $decodedActiveLengthCount = 0;
            $lastLengthIndex = $lap->firstLengthIndex
                + $lap->lengthCount - 1;
            $verifiable = true;

            for (
                $lengthIndex = $lap->firstLengthIndex;
                $lengthIndex <= $lastLengthIndex;
                ++$lengthIndex
            ) {
                $length = $lengthsByIndex[$lengthIndex] ?? null;

                if (null === $length) {
                    $verifiable = false;
                    break;
                }

                if (PoolLengthType::Active === $length->type) {
                    ++$decodedActiveLengthCount;
                }
            }

            if (
                !$verifiable
                || $lap->activeLengthCount === $decodedActiveLengthCount
            ) {
                continue;
            }

            $warnings[] = new ActivityImportWarning(
                code: ActivityImportWarningCode::LapActiveLengthCountMismatch,
                message: 'FIT lap active-length count differs from the fully indexed Length messages referenced by the lap.',
                context: [
                    'firstLengthIndex' => $lap->firstLengthIndex,
                    'lengthCount' => $lap->lengthCount,
                    'declaredActiveLengthCount' => $lap->activeLengthCount,
                    'decodedActiveLengthCount' => $decodedActiveLengthCount,
                ],
            );
        }

        return $warnings;
    }

    /**
     * Compare Session swim-length summary counts only when the full explicit
     * Session -> Lap -> Length reference chain is provable. Any unindexed or
     * duplicate Lap/Length, incomplete reference range, or Lap without
     * sufficient Length metadata makes the Session count unverifiable and no
     * count warning is emitted.
     *
     * @param list<ActivityDetailItem> $details
     * @param list<LapItem>            $laps
     * @param list<SessionItem>        $sessions
     *
     * @return list<ActivityImportWarning>
     */
    public function sessionLengthCountMismatches(
        array $details,
        array $laps,
        array $sessions,
    ): array {
        $lapsByIndex = [];

        foreach ($laps as $lap) {
            if (
                null === $lap->index
                || isset($lapsByIndex[$lap->index])
            ) {
                return [];
            }

            $lapsByIndex[$lap->index] = $lap;
        }

        $lengthsByIndex = [];

        foreach ($details as $detail) {
            if (!$detail->detail instanceof PoolLength) {
                continue;
            }

            if (
                null === $detail->index
                || isset($lengthsByIndex[$detail->index])
            ) {
                return [];
            }

            $lengthsByIndex[$detail->index] = $detail->detail;
        }

        $warnings = [];

        foreach ($sessions as $session) {
            if (
                (
                    null === $session->lengthCount
                    && null === $session->activeLengthCount
                )
                || null === $session->firstLapIndex
                || null === $session->lapCount
            ) {
                continue;
            }

            $decodedLengthCount = 0;
            $decodedActiveLengthCount = 0;
            $seenLengthIndexes = [];
            $verifiable = true;
            $lastLapIndex = $session->firstLapIndex
                + $session->lapCount - 1;

            for (
                $lapIndex = $session->firstLapIndex;
                $lapIndex <= $lastLapIndex;
                ++$lapIndex
            ) {
                $lap = $lapsByIndex[$lapIndex] ?? null;

                if (null === $lap || null === $lap->lengthCount) {
                    $verifiable = false;
                    break;
                }

                if (0 === $lap->lengthCount) {
                    continue;
                }

                if (null === $lap->firstLengthIndex) {
                    $verifiable = false;
                    break;
                }

                $lastLengthIndex = $lap->firstLengthIndex
                    + $lap->lengthCount - 1;

                for (
                    $lengthIndex = $lap->firstLengthIndex;
                    $lengthIndex <= $lastLengthIndex;
                    ++$lengthIndex
                ) {
                    $length = $lengthsByIndex[$lengthIndex] ?? null;

                    if (
                        null === $length
                        || isset($seenLengthIndexes[$lengthIndex])
                    ) {
                        $verifiable = false;
                        break 2;
                    }

                    $seenLengthIndexes[$lengthIndex] = true;
                    ++$decodedLengthCount;

                    if (PoolLengthType::Active === $length->type) {
                        ++$decodedActiveLengthCount;
                    }
                }
            }

            if (!$verifiable) {
                continue;
            }

            if (
                null !== $session->lengthCount
                && $session->lengthCount !== $decodedLengthCount
            ) {
                $warnings[] = new ActivityImportWarning(
                    code: ActivityImportWarningCode::SessionLengthCountMismatch,
                    message: 'FIT session length count differs from the fully indexed Length messages referenced through its laps.',
                    context: [
                        'firstLapIndex' => $session->firstLapIndex,
                        'lapCount' => $session->lapCount,
                        'declaredLengthCount' => $session->lengthCount,
                        'decodedLengthCount' => $decodedLengthCount,
                    ],
                );
            }

            if (
                null !== $session->activeLengthCount
                && $session->activeLengthCount
                    !== $decodedActiveLengthCount
            ) {
                $warnings[] = new ActivityImportWarning(
                    code: ActivityImportWarningCode::SessionActiveLengthCountMismatch,
                    message: 'FIT session active-length count differs from the fully indexed Length messages referenced through its laps.',
                    context: [
                        'firstLapIndex' => $session->firstLapIndex,
                        'lapCount' => $session->lapCount,
                        'declaredActiveLengthCount' => $session->activeLengthCount,
                        'decodedActiveLengthCount' => $decodedActiveLengthCount,
                    ],
                );
            }
        }

        return $warnings;
    }

    /**
     * Validate only explicit, fully verifiable FIT Session -> Lap references.
     * If any lap has no message_index, an apparently missing index may belong
     * to that unindexed lap and therefore is not a proven mismatch.
     *
     * @param list<LapItem>     $laps
     * @param list<SessionItem> $sessions
     *
     * @return list<ActivityImportWarning>
     */
    public function sessionLapReferenceMismatches(
        array $laps,
        array $sessions,
    ): array {
        foreach ($laps as $lap) {
            if (null === $lap->index) {
                return [];
            }
        }

        $availableLapIndexes = [];

        foreach ($laps as $lap) {
            $availableLapIndexes[$lap->index] = true;
        }

        $warnings = [];

        foreach ($sessions as $session) {
            if (
                null === $session->firstLapIndex
                || null === $session->lapCount
                || 0 === $session->lapCount
            ) {
                continue;
            }

            $firstMissingLapIndex = null;
            $missingLapCount = 0;
            $lastLapIndex = $session->firstLapIndex
                + $session->lapCount - 1;

            for (
                $lapIndex = $session->firstLapIndex;
                $lapIndex <= $lastLapIndex;
                ++$lapIndex
            ) {
                if (!isset($availableLapIndexes[$lapIndex])) {
                    $firstMissingLapIndex ??= $lapIndex;
                    ++$missingLapCount;
                }
            }

            if (0 === $missingLapCount) {
                continue;
            }

            $warnings[] = new ActivityImportWarning(
                code: ActivityImportWarningCode::SessionLapReferenceMismatch,
                message: 'FIT session references lap indexes that are not present in the fully indexed lap summaries.',
                context: [
                    'firstLapIndex' => $session->firstLapIndex,
                    'lapCount' => $session->lapCount,
                    'firstMissingLapIndex' => $firstMissingLapIndex,
                    'missingLapCount' => $missingLapCount,
                ],
            );
        }

        return $warnings;
    }

    /**
     * @param list<SessionItem> $sessions
     *
     * @return list<ActivityImportWarning>
     */
    public function sessionBoundaryResolutionOverlaps(
        array $sessions,
    ): array {
        $warnings = [];
        $previous = null;

        foreach ($sessions as $item) {
            $session = $item->session;

            if (
                null !== $previous
                && $session->startedAt->isBefore(
                    $previous->finishedAt,
                )
            ) {
                $overlapMicroseconds = Duration::between(
                    $session->startedAt,
                    $previous->finishedAt,
                )->toMicroseconds();

                if (
                    $session->adjacencyPolicy->allows(
                        previousEnd: $previous->finishedAt,
                        nextStart: $session->startedAt,
                    )
                ) {
                    $warnings[] = new ActivityImportWarning(
                        code: ActivityImportWarningCode::SessionBoundaryResolutionOverlap,
                        message: 'Adjacent session boundaries overlap within the accepted source adjacency tolerance; source durations were preserved.',
                        context: [
                            'previousFinishedAt' => self::formatInstant(
                                $previous->finishedAt,
                            ),
                            'startedAt' => self::formatInstant(
                                $session->startedAt,
                            ),
                            'overlapMicroseconds' => $overlapMicroseconds,
                            'resolutionMicroseconds' => $session->timelineResolution->value,
                        ],
                    );
                }
            }

            $previous = $session;
        }

        return $warnings;
    }

    private function segmentPartialCoordinatePair(
        UnifiedDataMessage $message,
        string $latitudeField,
        string $longitudeField,
    ): ?ActivityImportWarning {
        if (
            self::SEGMENT_LAP_GLOBAL_MESSAGE_NUMBER
            !== $message->globalMessageNumber()
        ) {
            return null;
        }

        try {
            $latitude = $this->values->byName(
                message: $message,
                fieldName: $latitudeField,
            );
            $longitude = $this->values->byName(
                message: $message,
                fieldName: $longitudeField,
            );
        } catch (InvalidFitActivityMessage) {
            // The mapper remains responsible for ambiguous field selection.
            return null;
        }

        if ((null === $latitude) === (null === $longitude)) {
            return null;
        }

        return new ActivityImportWarning(
            code: ActivityImportWarningCode::SegmentCoordinatePairSkipped,
            message: 'Optional FIT segment lap coordinate pair was ignored because only one coordinate field resolved to a scalar value.',
            context: [
                'sequence' => $message->sequence(),
                'byteOffset' => $message->byteOffset(),
                'latitudeField' => $latitudeField,
                'longitudeField' => $longitudeField,
                'resolvedField' => null === $latitude
                    ? $longitudeField
                    : $latitudeField,
                'unresolvedField' => null === $latitude
                    ? $latitudeField
                    : $longitudeField,
            ],
        );
    }

    private function sessionPartialCoordinatePair(
        UnifiedDataMessage $message,
        string $latitudeField,
        string $longitudeField,
    ): ?ActivityImportWarning {
        if (
            self::SESSION_GLOBAL_MESSAGE_NUMBER
            !== $message->globalMessageNumber()
        ) {
            return null;
        }

        try {
            $latitude = $this->values->byName(
                message: $message,
                fieldName: $latitudeField,
            );
            $longitude = $this->values->byName(
                message: $message,
                fieldName: $longitudeField,
            );
        } catch (InvalidFitActivityMessage) {
            // The mapper remains responsible for ambiguous field selection.
            return null;
        }

        if ((null === $latitude) === (null === $longitude)) {
            return null;
        }

        return new ActivityImportWarning(
            code: ActivityImportWarningCode::SessionCoordinatePairSkipped,
            message: 'Optional FIT session coordinate pair was ignored because only one coordinate field resolved to a scalar value.',
            context: [
                'sequence' => $message->sequence(),
                'byteOffset' => $message->byteOffset(),
                'latitudeField' => $latitudeField,
                'longitudeField' => $longitudeField,
                'resolvedField' => null === $latitude
                    ? $longitudeField
                    : $latitudeField,
                'unresolvedField' => null === $latitude
                    ? $latitudeField
                    : $longitudeField,
            ],
        );
    }

    /** @param list<SessionItem> $sessions */
    private function timerMismatch(
        ActivitySummaryItem $activitySummary,
        array $sessions,
    ): ?ActivityImportWarning {
        $sessionTimer = Duration::zero();

        foreach ($sessions as $session) {
            $sessionTimer = $sessionTimer->plus(
                $session->session->timerDuration,
            );
        }

        $activityTimer = $activitySummary->summary->timerDuration;

        if ($activityTimer->equals($sessionTimer)) {
            return null;
        }

        return new ActivityImportWarning(
            code: ActivityImportWarningCode::ActivityTimerMismatch,
            message: 'Activity timer differs from the sum of session timers; session timing was used.',
            context: [
                'activityTimerMicroseconds' => $activityTimer->toMicroseconds(),
                'sessionTimerMicroseconds' => $sessionTimer->toMicroseconds(),
            ],
        );
    }

    /**
     * @param list<ActivityDetailItem> $details
     * @param list<LapItem>            $laps
     * @param list<SessionItem>        $sessions
     */
    private function boundaryAdjustment(
        ActivitySummaryItem $activitySummary,
        array $details,
        array $laps,
        array $sessions,
        ?Instant $lastObservationAt,
        ?Instant $lastLifecycleAt,
    ): ?ActivityImportWarning {
        $lastSession = $sessions[0]->session;

        foreach ($sessions as $session) {
            if ($session->session->finishedAt->isAfter(
                $lastSession->finishedAt,
            )) {
                $lastSession = $session->session;
            }
        }

        $candidate = $lastObservationAt;
        $boundaryType = null === $candidate ? null : 'observation';

        foreach ($laps as $lap) {
            if (
                null === $candidate
                || $lap->lap->finishedAt->isAfter($candidate)
            ) {
                $candidate = $lap->lap->finishedAt;
                $boundaryType = 'lap';
            }
        }

        foreach ($details as $detail) {
            $finishedAt = $detail->detail->interval()->finishedAt;

            if (
                null === $candidate
                || $finishedAt->isAfter($candidate)
            ) {
                $candidate = $finishedAt;
                $boundaryType = 'activity_detail';
            }
        }

        if (
            null !== $lastLifecycleAt
            && (
                null === $candidate
                || $lastLifecycleAt->isAfter($candidate)
            )
        ) {
            $candidate = $lastLifecycleAt;
            $boundaryType = 'event';
        }

        if (
            null === $candidate
            || !$candidate->isAfter($lastSession->finishedAt)
        ) {
            return null;
        }

        $acceptedRecordExcess = 'observation' === $boundaryType
            && TemporalResolution::Second === $activitySummary->timelineResolution
            && (int) $candidate->toDateTimeImmutable()->format('U')
                - (int) $lastSession->finishedAt->toDateTimeImmutable()->format('U') <= 1;

        if (
            !$acceptedRecordExcess
            && !$activitySummary->timelineResolution->includesAtUpperBoundary(
                boundary: $lastSession->finishedAt,
                candidate: $candidate,
            )
        ) {
            return null;
        }

        return new ActivityImportWarning(
            code: ActivityImportWarningCode::TimestampBoundaryAdjusted,
            message: 'Activity end was extended to contain a source timestamp at the declared temporal resolution.',
            context: [
                'boundaryType' => $boundaryType,
                'sessionFinishedAt' => self::formatInstant(
                    $lastSession->finishedAt,
                ),
                'adjustedFinishedAt' => self::formatInstant($candidate),
                'resolutionMicroseconds' => $activitySummary->timelineResolution->value,
            ],
        );
    }

    private static function formatInstant(Instant $instant): string
    {
        return $instant
            ->toDateTimeImmutable()
            ->format('Y-m-d\TH:i:s.uP');
    }
}
