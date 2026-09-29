<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\ActivitySummaryItem;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\Activity\ValueObject\SummaryAdjacencyPolicy;
use Youmad\Endurance\ActivityFit\Value\FitMissingSummaryRecoveryResult;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final readonly class FitMissingSummaryRecovery
{
    /**
     * @param list<LapItem>     $laps
     * @param list<SessionItem> $sessions
     */
    public function recover(
        ?ActivitySummaryItem $activitySummary,
        array $laps,
        array $sessions,
        ?Instant $firstObservationAt,
        ?Instant $lastObservationAt,
        ?Instant $explicitTimerStartAt = null,
    ): FitMissingSummaryRecoveryResult {
        $warnings = [];

        if (
            null === $firstObservationAt
            || null === $lastObservationAt
            || (
                null !== $activitySummary
                && 1 !== $activitySummary->summary->sessionCount
            )
        ) {
            return new FitMissingSummaryRecoveryResult(
                laps: $laps,
                sessions: $sessions,
                warnings: $warnings,
            );
        }

        if ([] === $sessions) {
            $recoveredStartedAt = $firstObservationAt;

            if (
                null !== $explicitTimerStartAt
                && $recoveredStartedAt->isBefore($explicitTimerStartAt)
            ) {
                if ($lastObservationAt->isBefore($explicitTimerStartAt)) {
                    return new FitMissingSummaryRecoveryResult(
                        laps: $laps,
                        sessions: $sessions,
                        warnings: $warnings,
                    );
                }

                $recoveredStartedAt = $explicitTimerStartAt;
            }

            $observationDuration = Duration::between(
                $recoveredStartedAt,
                $lastObservationAt,
            );

            $sessions[] = new SessionItem(
                ActivitySession::create(
                    startedAt: $recoveredStartedAt,
                    finishedAt: $lastObservationAt,
                    timerDuration: $observationDuration,
                    timelineResolution: TemporalResolution::Second,
                    adjacencyPolicy: SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds,
                ),
            );
            $warnings[] = new ActivityImportWarning(
                code: ActivityImportWarningCode::MissingSessionRecovered,
                message: 'Session summary was recovered from available activity boundaries.',
                context: [
                    'startedAt' => self::instant($recoveredStartedAt),
                    'finishedAt' => self::instant($lastObservationAt),
                ],
            );
        }

        if ([] === $laps && 1 === count($sessions)) {
            $session = $sessions[0]->session;

            $laps[] = new LapItem(
                Lap::create(
                    startedAt: $session->startedAt,
                    finishedAt: $session->finishedAt,
                    timerDuration: $session->timerDuration,
                    timelineResolution: $session->timelineResolution,
                    adjacencyPolicy: $session->adjacencyPolicy,
                ),
            );
            $warnings[] = new ActivityImportWarning(
                code: ActivityImportWarningCode::MissingLapRecovered,
                message: 'Lap summary was recovered from the only activity session.',
                context: [
                    'startedAt' => self::instant($session->startedAt),
                    'finishedAt' => self::instant($session->finishedAt),
                ],
            );
        }

        return new FitMissingSummaryRecoveryResult(
            laps: $laps,
            sessions: $sessions,
            warnings: $warnings,
        );
    }

    private static function instant(Instant $instant): string
    {
        return $instant
            ->toDateTimeImmutable()
            ->format('Y-m-d\TH:i:s.uP');
    }
}
