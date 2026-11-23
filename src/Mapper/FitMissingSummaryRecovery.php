<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

use Endurance\Activity\Application\Import\ActivityImportWarning;
use Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Endurance\Activity\Application\Import\ActivitySummaryItem;
use Endurance\Activity\Application\Import\LapItem;
use Endurance\Activity\Application\Import\SessionItem;
use Endurance\Activity\Session\ActivitySession;
use Endurance\Activity\ValueObject\Lap;
use Endurance\Activity\ValueObject\SummaryAdjacencyPolicy;
use Endurance\ActivityFit\Value\FitMissingSummaryRecoveryResult;
use Endurance\Foundation\ValueObject\Duration;
use Endurance\Foundation\ValueObject\Instant;
use Endurance\Foundation\ValueObject\TemporalResolution;

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
