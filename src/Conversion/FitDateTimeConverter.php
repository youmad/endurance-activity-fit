<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Conversion;

use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Value\SelectedFitFieldValue;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final readonly class FitDateTimeConverter
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function convert(
        SelectedFitFieldValue $value,
    ): Instant {
        return $this->convertScalar($value->value);
    }

    /** @internal Optimized Record pipeline entry point. */
    public function convertScalar(
        int|float|string $value,
    ): Instant {
        $fitSeconds = $this->integerSeconds($value);

        if (
            PHP_INT_MAX
                - self::FIT_EPOCH_TO_UNIX_SECONDS
                < $fitSeconds
        ) {
            throw InvalidFitActivityMessage::invalidDateTime($value);
        }

        $unixSeconds = $fitSeconds
            + self::FIT_EPOCH_TO_UNIX_SECONDS;

        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable(
                sprintf('@%d', $unixSeconds),
            ),
        );
    }

    private function integerSeconds(
        int|float|string $value,
    ): int {
        if (is_int($value)) {
            if (0 > $value) {
                throw InvalidFitActivityMessage::invalidDateTime($value);
            }

            return $value;
        }

        if (
            is_float($value)
            && is_finite($value)
            && floor($value) === $value
            && 0.0 <= $value
            && PHP_INT_MAX >= $value
        ) {
            return (int) $value;
        }

        throw InvalidFitActivityMessage::invalidDateTime($value);
    }
}
