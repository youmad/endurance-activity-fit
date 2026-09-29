<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

/**
 * Mirrors Garmin ActivityFileValidationPlugin's REQUIRED
 * Session/Lap "Are Sequential and Abut" check.
 *
 * FIT start_time and total_elapsed_time are compared through the SDK's
 * whole-second getFieldLongValue() view. Consecutive summaries may differ
 * from the previous calculated end by at most two whole seconds.
 */
final class FitSummaryAdjacencyValidator
{
    private const int SESSION_GLOBAL_MESSAGE_NUMBER = 18;
    private const int LAP_GLOBAL_MESSAGE_NUMBER = 19;
    private const int MAX_ABUT_DELTA_SECONDS = 2;

    /** @var array<int, int> */
    private array $previousEndByGlobalMessageNumber = [];

    public function __construct(
        private readonly FitFieldValueReader $values =
            new FitFieldValueReader(),
    ) {
    }

    public function validate(UnifiedDataMessage $message): void
    {
        $messageName = match ($message->globalMessageNumber()) {
            self::SESSION_GLOBAL_MESSAGE_NUMBER => 'session',
            self::LAP_GLOBAL_MESSAGE_NUMBER => 'lap',
            default => null,
        };

        if (null === $messageName) {
            return;
        }

        $startTime = $this->wholeSeconds(
            message: $message,
            fieldName: 'start_time',
        );
        $totalElapsedTime = $this->wholeSeconds(
            message: $message,
            fieldName: 'total_elapsed_time',
        );

        // Required-field/type validation belongs to the normal FIT summary
        // mapper. Do not change its error precedence here.
        if (null === $startTime || null === $totalElapsedTime) {
            return;
        }

        if (PHP_INT_MAX - $startTime < $totalElapsedTime) {
            return;
        }

        $globalMessageNumber = $message->globalMessageNumber();
        $previousEnd = $this->previousEndByGlobalMessageNumber[
            $globalMessageNumber
        ] ?? null;

        if (null !== $previousEnd) {
            $deltaSeconds = $startTime >= $previousEnd
                ? $startTime - $previousEnd
                : $previousEnd - $startTime;

            if (self::MAX_ABUT_DELTA_SECONDS < $deltaSeconds) {
                throw InvalidFitActivityMessage::summaryMessagesDoNotAbut(message: $message, messageName: $messageName, startTime: $startTime, previousEndTime: $previousEnd, deltaSeconds: $deltaSeconds);
            }
        }

        $this->previousEndByGlobalMessageNumber[$globalMessageNumber] =
            $startTime + $totalElapsedTime;
    }

    public function reset(): void
    {
        $this->previousEndByGlobalMessageNumber = [];
    }

    private function wholeSeconds(
        UnifiedDataMessage $message,
        string $fieldName,
    ): ?int {
        $value = $this->values->byName(
            message: $message,
            fieldName: $fieldName,
        )?->value;

        if (!is_int($value) && !is_float($value)) {
            return null;
        }

        if (is_float($value) && !is_finite($value)) {
            return null;
        }

        if (0 > $value || PHP_INT_MAX < $value) {
            return null;
        }

        // Java Number.longValue() truncates toward zero. FIT durations are
        // non-negative, so the PHP integer cast is equivalent here.
        return (int) $value;
    }
}
