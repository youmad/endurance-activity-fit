<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

/**
 * Mirrors Garmin ActivityFileValidationPlugin 21.214 REQUIRED checks for
 * Activity/Session/Lap message presence, message indexes, Session-to-Lap
 * ranges, duration sums, referenced Lap boundaries, Record/Session boundaries
 * and Session sport presence.
 */
final class FitSessionLapValidator
{
    private const int ACTIVITY_GLOBAL_MESSAGE_NUMBER = 34;
    private const int SESSION_GLOBAL_MESSAGE_NUMBER = 18;
    private const int LAP_GLOBAL_MESSAGE_NUMBER = 19;
    private const int RECORD_GLOBAL_MESSAGE_NUMBER = 20;
    private const float MAX_DURATION_SUM_DELTA_SECONDS = 1.0;
    private const int MAX_LAP_END_DELTA_SECONDS = 1;
    private const string LAP_BOUNDARY_CHECK =
        'Lap Message Start Time and Timestamp are Valid';
    private const string RECORD_BOUNDARY_CHECK =
        'Record Message Timestamps Fall Within Session Message Times';

    private int $activityCount = 0;
    private bool $hasRecords = false;
    private ?int $firstRecordTimestamp = null;
    private ?int $lastRecordTimestamp = null;

    /**
     * @var list<array{
     *     message: UnifiedDataMessage,
     *     messageIndex: ?int,
     *     firstLapIndex: ?int,
     *     lapCount: ?int,
     *     timerTime: ?float,
     *     elapsedTime: ?float,
     *     sport: ?string
     * }>
     */
    private array $sessions = [];

    /**
     * @var list<array{
     *     message: UnifiedDataMessage,
     *     messageIndex: ?int,
     *     timerTime: ?float,
     *     elapsedTime: ?float
     * }>
     */
    private array $laps = [];

    public function __construct(
        private readonly FitFieldValueReader $values =
            new FitFieldValueReader(),
    ) {
    }

    public function observe(UnifiedDataMessage $message): void
    {
        if (self::RECORD_GLOBAL_MESSAGE_NUMBER === $message->globalMessageNumber()) {
            $this->observeRecordTimestamp($this->integer($message, 'timestamp'));

            return;
        }

        if (
            self::ACTIVITY_GLOBAL_MESSAGE_NUMBER
            === $message->globalMessageNumber()
        ) {
            ++$this->activityCount;

            return;
        }

        if (
            self::SESSION_GLOBAL_MESSAGE_NUMBER
            === $message->globalMessageNumber()
        ) {
            $this->sessions[] = [
                'message' => $message,
                'messageIndex' => $this->integer($message, 'message_index'),
                'firstLapIndex' => $this->integer($message, 'first_lap_index'),
                'lapCount' => $this->integer($message, 'num_laps'),
                'timerTime' => $this->number($message, 'total_timer_time'),
                'elapsedTime' => $this->number($message, 'total_elapsed_time'),
                'sport' => $this->values
                    ->byName($message, 'sport')
                    ?->symbolicName(),
            ];

            return;
        }

        if (
            self::LAP_GLOBAL_MESSAGE_NUMBER
            !== $message->globalMessageNumber()
        ) {
            return;
        }

        $this->laps[] = [
            'message' => $message,
            'messageIndex' => $this->integer($message, 'message_index'),
            'timerTime' => $this->number($message, 'total_timer_time'),
            'elapsedTime' => $this->number($message, 'total_elapsed_time'),
        ];
    }

    /** Retain source order, including Records which produce no Activity items. */
    public function observeRecordTimestamp(?int $timestamp): void
    {
        if (!$this->hasRecords) {
            $this->firstRecordTimestamp = $timestamp;
            $this->hasRecords = true;
        }
        $this->lastRecordTimestamp = $timestamp;
    }

    public function validate(
        bool $requireSummaryMessages = false,
    ): void {
        if ($requireSummaryMessages) {
            $this->validateRequiredMessagePresence();
        }

        $this->validateMessageIndexes(
            records: $this->sessions,
            messageName: 'session',
        );
        $this->validateMessageIndexes(
            records: $this->laps,
            messageName: 'lap',
        );
        $this->validateSessionSportPresence();
        $this->validateFirstRecordBoundary();
        $this->validateLastRecordBoundary();

        if ([] === $this->sessions || [] === $this->laps) {
            return;
        }

        $this->validateSessionLapRanges();
        $this->validateDurationSums(
            sessionField: 'timerTime',
            fitField: 'total_timer_time',
        );
        $this->validateDurationSums(
            sessionField: 'elapsedTime',
            fitField: 'total_elapsed_time',
        );
        $this->validateLapBoundaries();
    }

    public function reset(): void
    {
        $this->activityCount = 0;
        $this->hasRecords = false;
        $this->firstRecordTimestamp = null;
        $this->lastRecordTimestamp = null;
        $this->sessions = [];
        $this->laps = [];
    }

    private function validateRequiredMessagePresence(): void
    {
        foreach (
            [
                'Activity' => $this->activityCount,
                'Session' => count($this->sessions),
                'Lap' => count($this->laps),
            ] as $messageName => $count
        ) {
            if (0 < $count) {
                continue;
            }

            throw InvalidFitActivityMessage::garminRequiredStreamCheckFailed(check: sprintf('%s Message Exists', $messageName), detail: sprintf('no %s messages were decoded', $messageName));
        }
    }

    /**
     * @param list<array{message: UnifiedDataMessage, messageIndex: ?int}> $records
     */
    private function validateMessageIndexes(
        array $records,
        string $messageName,
    ): void {
        foreach ($records as $expectedIndex => $record) {
            if ($expectedIndex === $record['messageIndex']) {
                continue;
            }

            throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $record['message'], check: sprintf('%s Message Valid Message Index', ucfirst($messageName)), detail: sprintf('expected message_index=%d; got %s', $expectedIndex, null === $record['messageIndex'] ? 'missing' : (string) $record['messageIndex']));
        }
    }

    private function validateSessionSportPresence(): void
    {
        foreach ($this->sessions as $session) {
            if (null !== $session['sport']) {
                continue;
            }

            throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $session['message'], check: 'Session Message Sport Exists', detail: 'sport is missing or has no Profile symbolic value');
        }
    }

    private function validateSessionLapRanges(): void
    {
        $expectedFirstLapIndex = 0;

        foreach ($this->sessions as $session) {
            if (
                null === $session['firstLapIndex']
                || null === $session['lapCount']
            ) {
                throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $session['message'], check: 'Session Message First Lap Index and Num Laps are Valid', detail: 'first_lap_index and num_laps are required when Lap messages exist');
            }

            if ($expectedFirstLapIndex !== $session['firstLapIndex']) {
                throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $session['message'], check: 'Session Message First Lap Index and Num Laps are Valid', detail: sprintf('expected first_lap_index=%d; got %d', $expectedFirstLapIndex, $session['firstLapIndex']));
            }

            $expectedFirstLapIndex += $session['lapCount'];
        }

        if (count($this->laps) === $expectedFirstLapIndex) {
            return;
        }

        $lastSession = $this->sessions[count($this->sessions) - 1];

        throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $lastSession['message'], check: 'Session Message First Lap Index and Num Laps are Valid', detail: sprintf('sum of num_laps=%d; decoded Lap messages=%d', $expectedFirstLapIndex, count($this->laps)));
    }

    /**
     * @param 'timerTime'|'elapsedTime' $sessionField
     */
    private function validateDurationSums(
        string $sessionField,
        string $fitField,
    ): void {
        foreach ($this->sessions as $session) {
            $firstLapIndex = $session['firstLapIndex'];
            $lapCount = $session['lapCount'];

            if (
                null === $firstLapIndex
                || null === $lapCount
                || 0 === $lapCount
            ) {
                continue;
            }

            $laps = array_slice($this->laps, $firstLapIndex, $lapCount);
            $declared = $session[$sessionField];
            $sum = 0.0;

            if (null === $declared) {
                $this->throwDurationSumMismatch(
                    message: $session['message'],
                    fitField: $fitField,
                    detail: sprintf('Session %s is missing', $fitField),
                );
            }

            foreach ($laps as $lap) {
                $value = $lap[$sessionField];

                if (null === $value) {
                    $this->throwDurationSumMismatch(
                        message: $lap['message'],
                        fitField: $fitField,
                        detail: sprintf('Lap %s is missing', $fitField),
                    );
                }

                $sum += $value;
            }

            if (
                abs($declared - $sum)
                <= self::MAX_DURATION_SUM_DELTA_SECONDS
            ) {
                continue;
            }

            $this->throwDurationSumMismatch(
                message: $session['message'],
                fitField: $fitField,
                detail: sprintf(
                    'Session value=%s; referenced Lap sum=%s; delta=%s seconds',
                    self::formatNumber($declared),
                    self::formatNumber($sum),
                    self::formatNumber(abs($declared - $sum)),
                ),
            );
        }
    }

    private function throwDurationSumMismatch(
        UnifiedDataMessage $message,
        string $fitField,
        string $detail,
    ): never {
        throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $message, check: sprintf('Session Message %s is Equal to the Sum of Lap Messages %s Values', $fitField, $fitField), detail: $detail);
    }

    private function validateFirstRecordBoundary(): void
    {
        if ([] === $this->sessions || !$this->hasRecords) {
            return;
        }

        $firstSession = $this->sessions[0]['message'];
        $firstStart = $this->integer($firstSession, 'start_time');

        if (null === $firstStart || null === $this->firstRecordTimestamp) {
            throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $firstSession, check: self::RECORD_BOUNDARY_CHECK, detail: 'first Session start_time and first Record timestamp are required');
        }

        // This is the lower-bound branch of Garmin's Record/Session check.
        // Use the first source Record, even if it produced no Activity item.
        if ($this->firstRecordTimestamp < $firstStart - 1) {
            throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $firstSession, check: self::RECORD_BOUNDARY_CHECK, detail: sprintf('first Record timestamp=%d is earlier than first Session start_time=%d by %d seconds; maximum lead is 1 second', $this->firstRecordTimestamp, $firstStart, $firstStart - $this->firstRecordTimestamp));
        }
    }

    private function validateLastRecordBoundary(): void
    {
        if ([] === $this->sessions || !$this->hasRecords) {
            return;
        }

        $lastSession = $this->sessions[array_key_last($this->sessions)];
        $message = $lastSession['message'];
        $start = $this->integer($message, 'start_time');
        $elapsed = $lastSession['elapsedTime'];

        if (null === $start || null === $elapsed || null === $this->lastRecordTimestamp) {
            throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $message, check: self::RECORD_BOUNDARY_CHECK, detail: 'last Session start_time and total_elapsed_time and last Record timestamp are required');
        }

        // Garmin calculates the end from start_time + whole elapsed seconds,
        // not Session.timestamp. Use the last source Record, including empty
        // projections, rather than the latest mapped observation.
        $end = $start + (int) $elapsed;
        if ($this->lastRecordTimestamp > $end + 1) {
            throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $message, check: self::RECORD_BOUNDARY_CHECK, detail: sprintf('last Record timestamp=%d is later than last Session calculated end=%d by %d seconds; maximum excess is 1 second', $this->lastRecordTimestamp, $end, $this->lastRecordTimestamp - $end));
        }
    }

    private function validateLapBoundaries(): void
    {
        $lapIntervals = [];
        foreach ($this->laps as $lap) {
            $lapIntervals[] = $this->wholeSecondInterval($lap['message']);
        }

        foreach ($this->sessions as $session) {
            $sessionInterval = $this->wholeSecondInterval($session['message']);
            $firstLapIndex = $session['firstLapIndex'];
            $lapCount = $session['lapCount'];

            // Ranges have already been validated. A Session with no referenced
            // Laps still needs its required timing fields, as in Garmin SDK.
            if (null === $firstLapIndex || null === $lapCount || 0 === $lapCount) {
                continue;
            }

            for ($index = $firstLapIndex; $index < $firstLapIndex + $lapCount; ++$index) {
                $lapInterval = $lapIntervals[$index];
                $endDelta = $lapInterval['end'] - $sessionInterval['end'];

                if (
                    $lapInterval['start'] >= $sessionInterval['start']
                    && $endDelta <= self::MAX_LAP_END_DELTA_SECONDS
                ) {
                    continue;
                }

                throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $this->laps[$index]['message'], check: self::LAP_BOUNDARY_CHECK, detail: sprintf('Lap message_index=%d interval=[%d, %d]; referenced Session message_index=%d interval=[%d, %d]; end delta=%d seconds (whole FIT seconds)', $index, $lapInterval['start'], $lapInterval['end'], $session['messageIndex'], $sessionInterval['start'], $sessionInterval['end'], $endDelta));
            }
        }
    }

    /** @return array{start: int, end: int} */
    private function wholeSecondInterval(UnifiedDataMessage $message): array
    {
        $start = $this->integer($message, 'start_time');
        $timestamp = $this->integer($message, 'timestamp');
        $elapsed = $this->number($message, 'total_elapsed_time');

        if (null === $start || null === $timestamp || null === $elapsed) {
            throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $message, check: self::LAP_BOUNDARY_CHECK, detail: 'start_time, timestamp and total_elapsed_time are required for Session/Lap containment');
        }

        // Garmin requires timestamp to exist, but calculates the interval end
        // from start_time + getFieldLongValue("total_elapsed_time"). Use the
        // original message values, before Activity timing normalization.
        return ['start' => $start, 'end' => $start + (int) $elapsed];
    }

    private function integer(
        UnifiedDataMessage $message,
        string $fieldName,
    ): ?int {
        $value = $this->values->byName($message, $fieldName)?->value;

        return is_int($value) ? $value : null;
    }

    private function number(
        UnifiedDataMessage $message,
        string $fieldName,
    ): ?float {
        $value = $this->values->byName($message, $fieldName)?->value;

        if (
            (!is_int($value) && !is_float($value))
            || !is_finite((float) $value)
        ) {
            return null;
        }

        return (float) $value;
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
    }
}
