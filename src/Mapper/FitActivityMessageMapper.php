<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\ActivitySummaryItem;
use Youmad\Endurance\Activity\Summary\ActivitySummary;
use Youmad\Endurance\ActivityFit\Conversion\FitDateTimeConverter;
use Youmad\Endurance\ActivityFit\Conversion\FitDurationConverter;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Value\FitActivitySummarySource;
use Youmad\Endurance\ActivityFit\Value\SelectedFitFieldValue;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final readonly class FitActivityMessageMapper implements FitMessageMapper
{
    private const int ACTIVITY_GLOBAL_MESSAGE_NUMBER = 34;
    private const int MINIMUM_LOCAL_TIME_OFFSET_SECONDS = -43_200;
    private const int MAXIMUM_LOCAL_TIME_OFFSET_SECONDS = 50_400;

    public function __construct(
        private FitFieldValueReader $values =
            new FitFieldValueReader(),
        private FitDateTimeConverter $dateTimes =
            new FitDateTimeConverter(),
        private FitDurationConverter $durations =
            new FitDurationConverter(),
    ) {
    }

    public function supports(
        UnifiedDataMessage $message,
    ): bool {
        return self::ACTIVITY_GLOBAL_MESSAGE_NUMBER
            === $message->globalMessageNumber();
    }

    /**
     * @return list<ActivityImportItem>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array {
        $source = $this->read($message);

        if (null === $source->sessionCount) {
            throw InvalidFitActivityMessage::missingRequiredField(message: $message, messageName: 'activity', fieldName: 'num_sessions');
        }

        if (1 > $source->sessionCount) {
            throw InvalidFitActivityMessage::invalidSessionCount($source->sessionCount);
        }

        if (null === $source->timerDuration) {
            throw InvalidFitActivityMessage::missingRequiredField(message: $message, messageName: 'activity', fieldName: 'total_timer_time');
        }

        return [new ActivitySummaryItem(
            summary: ActivitySummary::create(
                reportedAt: $source->reportedAt,
                timerDuration: $source->timerDuration,
                sessionCount: $source->sessionCount,
                type: $source->type,
                localTimeOffsetSeconds: $source->localTimeOffsetSeconds,
            ),
            timelineResolution: $source->timelineResolution,
        )];
    }

    public function read(
        UnifiedDataMessage $message,
    ): FitActivitySummarySource {
        if (!$this->supports($message)) {
            throw new \LogicException(sprintf('FIT activity mapper does not support global message %d.', $message->globalMessageNumber()));
        }

        $timestamp = $this->requiredValue(
            message: $message,
            fieldName: 'timestamp',
        );
        $sessionCount = $this->values->byName(
            message: $message,
            fieldName: 'num_sessions',
        );
        $timerDuration = $this->values->byName(
            message: $message,
            fieldName: 'total_timer_time',
        );
        $localTimestamp = $this->values->byName(
            message: $message,
            fieldName: 'local_timestamp',
        );

        if (null === $localTimestamp) {
            throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $message, check: 'Activity Message Local Timestamp is Valid', detail: 'Local Timestamp is null.');
        }

        return new FitActivitySummarySource(
            message: $message,
            reportedAt: $this->dateTimes->convert($timestamp),
            timerDuration: null === $timerDuration
                ? null
                : $this->durations->convert($timerDuration),
            sessionCount: null === $sessionCount
                ? null
                : $this->sessionCount($sessionCount),
            type: $this->values->byName(
                message: $message,
                fieldName: 'type',
            )?->symbolicName(),
            localTimeOffsetSeconds: $this->localTimeOffsetSeconds(
                message: $message,
                timestamp: $timestamp,
                localTimestamp: $localTimestamp,
            ),
            timelineResolution: TemporalResolution::Second,
        );
    }

    private function requiredValue(
        UnifiedDataMessage $message,
        string $fieldName,
    ): SelectedFitFieldValue {
        $value = $this->values->byName(
            message: $message,
            fieldName: $fieldName,
        );

        if (null === $value) {
            throw InvalidFitActivityMessage::missingRequiredField(message: $message, messageName: 'activity', fieldName: $fieldName);
        }

        return $value;
    }

    private function localTimeOffsetSeconds(
        UnifiedDataMessage $message,
        SelectedFitFieldValue $timestamp,
        SelectedFitFieldValue $localTimestamp,
    ): int {
        $offset = $this->integerSeconds($localTimestamp)
            - $this->integerSeconds($timestamp);

        if (
            self::MINIMUM_LOCAL_TIME_OFFSET_SECONDS > $offset
            || self::MAXIMUM_LOCAL_TIME_OFFSET_SECONDS < $offset
        ) {
            throw InvalidFitActivityMessage::garminRequiredCheckFailed(message: $message, check: 'Activity Message Local Timestamp is Valid', detail: sprintf('Local Timestamp is not within the tolerated range of [-12,14] hours; got an offset of %d seconds.', $offset));
        }

        return $offset;
    }

    private function integerSeconds(
        SelectedFitFieldValue $value,
    ): int {
        if (is_int($value->value) && 0 <= $value->value) {
            return $value->value;
        }

        if (
            is_float($value->value)
            && is_finite($value->value)
            && floor($value->value) === $value->value
            && 0.0 <= $value->value
            && PHP_INT_MAX >= $value->value
        ) {
            return (int) $value->value;
        }

        throw InvalidFitActivityMessage::invalidDateTime($value->value);
    }

    private function sessionCount(
        SelectedFitFieldValue $value,
    ): int {
        if (
            !is_int($value->value)
            || 0 > $value->value
            || 65_535 < $value->value
        ) {
            throw InvalidFitActivityMessage::invalidSessionCount($value->value);
        }

        return $value->value;
    }
}
