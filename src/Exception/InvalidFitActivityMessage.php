<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Exception;

use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

final class InvalidFitActivityMessage extends \RuntimeException
{
    public static function missingRequiredField(
        UnifiedDataMessage $message,
        string $messageName,
        string $fieldName,
    ): self {
        return new self(
            sprintf(
                'FIT %s at sequence %d and byte offset %d has no usable %s field.',
                $messageName,
                $message->sequence(),
                $message->byteOffset(),
                $fieldName,
            ),
        );
    }

    public static function partialPosition(
        UnifiedDataMessage $message,
    ): self {
        return new self(
            sprintf(
                'FIT record at sequence %d and byte offset %d must contain both position_lat and position_long or neither.',
                $message->sequence(),
                $message->byteOffset(),
            ),
        );
    }

    public static function partialCoordinatePair(
        UnifiedDataMessage $message,
        string $messageName,
        string $latitudeField,
        string $longitudeField,
    ): self {
        return new self(
            sprintf(
                'FIT %s at sequence %d and byte offset %d must contain both %s and %s or neither.',
                $messageName,
                $message->sequence(),
                $message->byteOffset(),
                $latitudeField,
                $longitudeField,
            ),
        );
    }

    public static function unsupportedFileType(
        ?string $symbolicType,
        int|float|string $rawType,
    ): self {
        return new self(
            sprintf(
                'FIT activity importer does not support file type %s.',
                $symbolicType ?? (string) $rawType,
            ),
        );
    }

    public static function invalidText(
        string $fieldName,
        mixed $value,
    ): self {
        return new self(
            sprintf(
                'FIT field %s must contain one text scalar; got %s.',
                $fieldName,
                get_debug_type($value),
            ),
        );
    }

    public static function invalidScalar(
        string $fieldName,
        mixed $value,
    ): self {
        return new self(
            sprintf(
                'FIT field %s must contain one numeric scalar; got %s.',
                $fieldName,
                get_debug_type($value),
            ),
        );
    }

    public static function ambiguousField(
        string $fieldName,
        int $valueCount,
    ): self {
        return new self(
            sprintf(
                'FIT field %s resolves to %d scalar values where exactly one is required.',
                $fieldName,
                $valueCount,
            ),
        );
    }

    public static function duplicateFieldName(
        string $fieldName,
    ): self {
        return new self(
            sprintf(
                'Unified FIT message contains field name %s more than once.',
                $fieldName,
            ),
        );
    }

    public static function invalidDateTime(
        mixed $value,
    ): self {
        return new self(
            sprintf(
                'FIT date_time must be a non-negative integer number of FIT epoch seconds; got %s.',
                is_scalar($value)
                    ? (string) $value
                    : get_debug_type($value),
            ),
        );
    }

    public static function invalidDuration(
        string $fieldName,
        mixed $value,
    ): self {
        return new self(
            sprintf(
                'FIT duration field %s must be a finite non-negative number of seconds; got %s.',
                $fieldName,
                is_scalar($value)
                    ? (string) $value
                    : get_debug_type($value),
            ),
        );
    }

    public static function timerDurationExceedsElapsed(
        string $messageName,
        int $timerMicroseconds,
        int $elapsedMicroseconds,
    ): self {
        return new self(
            sprintf(
                'FIT %s total_timer_time (%d microseconds) cannot exceed total_elapsed_time (%d microseconds).',
                $messageName,
                $timerMicroseconds,
                $elapsedMicroseconds,
            ),
        );
    }

    public static function invalidSessionCount(
        mixed $value,
    ): self {
        return new self(
            sprintf(
                'FIT activity num_sessions must be an integer between 1 and 65535; got %s.',
                is_scalar($value)
                    ? (string) $value
                    : get_debug_type($value),
            ),
        );
    }

    public static function unexpectedFieldValue(
        UnifiedDataMessage $message,
        string $messageName,
        string $fieldName,
        string $expected,
        string $actual,
    ): self {
        return new self(
            sprintf(
                'FIT %s at sequence %d and byte offset %d expects %s=%s; got %s.',
                $messageName,
                $message->sequence(),
                $message->byteOffset(),
                $fieldName,
                $expected,
                $actual,
            ),
        );
    }

    public static function duplicateActivitySummary(): self
    {
        return new self(
            'FIT activity stream contains more than one activity summary message.',
        );
    }

    public static function invalidDeviceIndex(
        mixed $value,
    ): self {
        return new self(
            sprintf(
                'FIT device_index must be an integer between 0 and 255; got %s.',
                is_scalar($value)
                    ? (string) $value
                    : get_debug_type($value),
            ),
        );
    }

    public static function invalidSemicircles(
        string $fieldName,
        mixed $value,
    ): self {
        return new self(
            sprintf(
                'FIT field %s must be a signed integer in semicircles; got %s.',
                $fieldName,
                is_scalar($value)
                    ? (string) $value
                    : get_debug_type($value),
            ),
        );
    }

    public static function summaryMessagesDoNotAbut(
        UnifiedDataMessage $message,
        string $messageName,
        int $startTime,
        int $previousEndTime,
        int $deltaSeconds,
    ): self {
        return new self(
            sprintf(
                'FIT %s at sequence %d and byte offset %d is not sequential with the previous %s or does not abut it: start_time=%d, previous_end_time=%d, whole-second delta=%d.',
                $messageName,
                $message->sequence(),
                $message->byteOffset(),
                $messageName,
                $startTime,
                $previousEndTime,
                $deltaSeconds,
            ),
        );
    }

    public static function garminRequiredCheckFailed(
        UnifiedDataMessage $message,
        string $check,
        string $detail,
    ): self {
        return new self(
            sprintf(
                'FIT message at sequence %d and byte offset %d failed Garmin required check "%s": %s.',
                $message->sequence(),
                $message->byteOffset(),
                $check,
                $detail,
            ),
        );
    }

    public static function garminRequiredStreamCheckFailed(
        string $check,
        string $detail,
    ): self {
        return new self(
            sprintf(
                'FIT activity stream failed Garmin required check "%s": %s.',
                $check,
                $detail,
            ),
        );
    }

    public static function timerEventOutOfOrder(
        UnifiedDataMessage $message,
        \DateTimeImmutable $occurredAt,
        \DateTimeImmutable $previousAt,
    ): self {
        return new self(
            sprintf(
                'FIT timer event at sequence %d and byte offset %d occurs at %s before previous timer event %s.',
                $message->sequence(),
                $message->byteOffset(),
                $occurredAt->format('Y-m-d\TH:i:s.uP'),
                $previousAt->format('Y-m-d\TH:i:s.uP'),
            ),
        );
    }
}
