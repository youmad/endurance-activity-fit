<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

use Endurance\Activity\Application\Import\ActivityImportWarning;
use Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Endurance\Activity\Application\Import\ActivitySummaryItem;
use Endurance\Activity\Application\Import\SessionItem;
use Endurance\Activity\Summary\ActivitySummary;
use Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Endurance\ActivityFit\Value\FitActivitySummaryRecoveryResult;
use Endurance\ActivityFit\Value\FitActivitySummarySource;
use Endurance\Foundation\ValueObject\Duration;
use Endurance\Foundation\ValueObject\Instant;

final readonly class FitActivitySummaryRecovery
{
    /**
     * @param list<SessionItem> $sessions
     */
    public function recover(
        FitActivitySummarySource $source,
        array $sessions,
    ): FitActivitySummaryRecoveryResult {
        $sessionCountRecovered = null === $source->sessionCount;
        $timerDurationRecovered = null === $source->timerDuration;
        $decodedSessionCount = count($sessions);
        $sessionCountMismatch = !$sessionCountRecovered
            && [] !== $sessions
            && $source->sessionCount !== $decodedSessionCount;

        if (
            ($sessionCountRecovered || $timerDurationRecovered)
            && [] === $sessions
        ) {
            throw InvalidFitActivityMessage::missingRequiredField(message: $source->message, messageName: 'activity', fieldName: $sessionCountRecovered ? 'num_sessions' : 'total_timer_time');
        }

        $sessionCount = $sessionCountMismatch
            ? $decodedSessionCount
            : ($source->sessionCount ?? $decodedSessionCount);

        if (1 > $sessionCount || 65_535 < $sessionCount) {
            throw InvalidFitActivityMessage::invalidSessionCount($sessionCount);
        }

        $timerDuration = $source->timerDuration
            ?? $this->sessionTimerDuration($sessions);
        $reportedAt = $source->reportedAt;
        $latestSessionFinishedAt = $this->latestSessionFinishedAt(
            $sessions,
        );
        $timestampAdjusted = null !== $latestSessionFinishedAt
            && $reportedAt->isBefore($latestSessionFinishedAt);

        if ($timestampAdjusted) {
            $reportedAt = $latestSessionFinishedAt;
        }

        $activitySummary = new ActivitySummaryItem(
            summary: ActivitySummary::create(
                reportedAt: $reportedAt,
                timerDuration: $timerDuration,
                sessionCount: $sessionCount,
                type: $source->type,
                localTimeOffsetSeconds: $source->localTimeOffsetSeconds,
            ),
            timelineResolution: $source->timelineResolution,
        );

        $warnings = [];

        if ($sessionCountRecovered || $timerDurationRecovered) {
            $warnings[] = new ActivityImportWarning(
                code: ActivityImportWarningCode::ActivitySummaryFieldsRecovered,
                message: 'Missing FIT activity summary fields were recovered from decoded session summaries.',
                context: [
                    'sessionCountRecovered' => $sessionCountRecovered,
                    'timerDurationRecovered' => $timerDurationRecovered,
                    'recoveredSessionCount' => $sessionCount,
                    'recoveredTimerMicroseconds' => $timerDuration->toMicroseconds(),
                ],
            );
        }

        if ($timestampAdjusted) {
            $warnings[] = new ActivityImportWarning(
                code: ActivityImportWarningCode::ActivitySummaryTimestampAdjusted,
                message: 'FIT activity timestamp preceded the final decoded Session boundary and was adjusted for the domain summary.',
                context: [
                    'sourceTimestamp' => self::instant(
                        $source->reportedAt,
                    ),
                    'adjustedReportedAt' => self::instant($reportedAt),
                ],
            );
        }

        if ($sessionCountMismatch) {
            $warnings[] = new ActivityImportWarning(
                code: ActivityImportWarningCode::ActivitySessionCountMismatch,
                message: 'FIT activity num_sessions differs from the decoded Session message count; decoded sessions were preserved.',
                context: [
                    'declaredSessionCount' => $source->sessionCount,
                    'decodedSessionCount' => $decodedSessionCount,
                ],
            );
        }

        return new FitActivitySummaryRecoveryResult(
            activitySummary: $activitySummary,
            warnings: $warnings,
        );
    }

    /** @param list<SessionItem> $sessions */
    private function latestSessionFinishedAt(array $sessions): ?Instant
    {
        $latest = null;

        foreach ($sessions as $session) {
            $finishedAt = $session->session->finishedAt;

            if (null === $latest || $finishedAt->isAfter($latest)) {
                $latest = $finishedAt;
            }
        }

        return $latest;
    }

    private static function instant(Instant $instant): string
    {
        return $instant
            ->toDateTimeImmutable()
            ->format('Y-m-d\TH:i:s.uP');
    }

    /** @param list<SessionItem> $sessions */
    private function sessionTimerDuration(
        array $sessions,
    ): Duration {
        $duration = Duration::zero();

        foreach ($sessions as $session) {
            $duration = $duration->plus(
                $session->session->timerDuration,
            );
        }

        return $duration;
    }
}
