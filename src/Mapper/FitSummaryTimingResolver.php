<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\ActivityFit\Conversion\FitDateTimeConverter;
use Youmad\Endurance\ActivityFit\Conversion\FitDurationConverter;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Value\ResolvedFitSummaryTiming;
use Youmad\Endurance\ActivityFit\Value\SelectedFitFieldValue;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final readonly class FitSummaryTimingResolver
{
    private const int LAP_GLOBAL_MESSAGE_NUMBER = 19;
    private const int MICROSECONDS_PER_SECOND = 1_000_000;

    public function __construct(
        private FitFieldValueReader $values =
            new FitFieldValueReader(),
        private FitDateTimeConverter $dateTimes =
            new FitDateTimeConverter(),
        private FitDurationConverter $durations =
            new FitDurationConverter(),
    ) {
    }

    public function resolve(
        UnifiedDataMessage $message,
        string $messageName,
    ): ResolvedFitSummaryTiming {
        $startedAt = $this->dateTimes->convert(
            $this->requiredValue(
                message: $message,
                messageName: $messageName,
                fieldName: 'start_time',
            ),
        );

        // Timestamp remains required by the FIT summary contract, but it is
        // the time the summary was written and cannot define its end time.
        $this->dateTimes->convert(
            $this->requiredValue(
                message: $message,
                messageName: $messageName,
                fieldName: 'timestamp',
            ),
        );

        $elapsedDuration = $this->durations->convert(
            $this->requiredValue(
                message: $message,
                messageName: $messageName,
                fieldName: 'total_elapsed_time',
            ),
        );

        $timerDuration = $this->durations->convert(
            $this->requiredValue(
                message: $message,
                messageName: $messageName,
                fieldName: 'total_timer_time',
            ),
        );

        // Garmin validates Session timer <= elapsed, but has no equivalent
        // REQUIRED Lap check. Do not invent a tolerance or extend a Lap end:
        // adjacency and Session/Lap sums must use the reported elapsed value.
        if (
            self::LAP_GLOBAL_MESSAGE_NUMBER !== $message->globalMessageNumber()
            && $timerDuration->isLongerThan($elapsedDuration)
        ) {
            $excessMicroseconds = $timerDuration
                ->minus($elapsedDuration)
                ->toMicroseconds();

            if (
                $excessMicroseconds
                >= TemporalResolution::Second->value
            ) {
                throw InvalidFitActivityMessage::timerDurationExceedsElapsed(messageName: $messageName, timerMicroseconds: $timerDuration->toMicroseconds(), elapsedMicroseconds: $elapsedDuration->toMicroseconds());
            }

            // FIT summary boundaries are second-resolution timestamps. Some
            // legacy devices preserve a sub-second timer duration while the
            // elapsed boundary is rounded to that coarser timeline. Preserve
            // the more precise timer and extend the effective elapsed duration
            // only within that declared source resolution.
            $elapsedDuration = $timerDuration;
        }

        return ResolvedFitSummaryTiming::create(
            startedAt: $startedAt,
            finishedAt: $this->addDuration(
                instant: $startedAt,
                duration: $elapsedDuration,
            ),
            elapsedDuration: $elapsedDuration,
            timerDuration: $timerDuration,
        );
    }

    private function requiredValue(
        UnifiedDataMessage $message,
        string $messageName,
        string $fieldName,
    ): SelectedFitFieldValue {
        $value = $this->values->byName(
            message: $message,
            fieldName: $fieldName,
        );

        if (null === $value) {
            throw InvalidFitActivityMessage::missingRequiredField(message: $message, messageName: $messageName, fieldName: $fieldName);
        }

        return $value;
    }

    private function addDuration(
        Instant $instant,
        Duration $duration,
    ): Instant {
        $microseconds = $duration->toMicroseconds();
        $seconds = intdiv(
            $microseconds,
            self::MICROSECONDS_PER_SECOND,
        );
        $remainingMicroseconds = $microseconds
            % self::MICROSECONDS_PER_SECOND;

        $value = $instant->toDateTimeImmutable();

        if (0 < $seconds) {
            $value = $value->modify(
                sprintf(
                    '+%d seconds',
                    $seconds,
                ),
            );
        }

        if (0 < $remainingMicroseconds) {
            $value = $value->modify(
                sprintf(
                    '+%d microseconds',
                    $remainingMicroseconds,
                ),
            );
        }

        return Instant::fromDateTimeImmutable($value);
    }
}
