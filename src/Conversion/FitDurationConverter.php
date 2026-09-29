<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Conversion;

use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Value\SelectedFitFieldValue;
use Youmad\Endurance\Foundation\ValueObject\Duration;

final readonly class FitDurationConverter
{
    private const int MICROSECONDS_PER_SECOND = 1_000_000;

    public function convert(
        SelectedFitFieldValue $value,
    ): Duration {
        $seconds = $value->value;

        if (
            !is_int($seconds)
            && !is_float($seconds)
        ) {
            throw InvalidFitActivityMessage::invalidDuration(fieldName: $value->fieldName() ?? 'duration', value: $seconds);
        }

        if (
            is_float($seconds)
            && !is_finite($seconds)
        ) {
            throw InvalidFitActivityMessage::invalidDuration(fieldName: $value->fieldName() ?? 'duration', value: $seconds);
        }

        if (0 > $seconds) {
            throw InvalidFitActivityMessage::invalidDuration(fieldName: $value->fieldName() ?? 'duration', value: $seconds);
        }

        $microseconds = $seconds
            * self::MICROSECONDS_PER_SECOND;

        if (
            !is_finite((float) $microseconds)
            || PHP_INT_MAX < $microseconds
        ) {
            throw InvalidFitActivityMessage::invalidDuration(fieldName: $value->fieldName() ?? 'duration', value: $seconds);
        }

        return Duration::fromMicroseconds(
            (int) round($microseconds),
        );
    }
}
