<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

use Endurance\Activity\Application\Import\ActivityDetailItem;
use Endurance\Activity\Application\Import\ActivityImportWarning;
use Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Endurance\Activity\Application\Import\LapItem;
use Endurance\Activity\Application\Import\SessionItem;
use Endurance\ActivityFit\Value\FitTerminalFinishResolution;
use Endurance\Foundation\ValueObject\Duration;
use Endurance\Foundation\ValueObject\Instant;
use Endurance\Foundation\ValueObject\TemporalResolution;

/** Stateless resolution of the fallback terminal timer boundary. */
final readonly class FitTerminalFinishResolver
{
    /**
     * A terminal timer Event timestamp has second resolution, while FIT summary
     * durations may preserve milliseconds. When the Activity message is
     * missing, keep stop_all as the fallback finalizer but extend it only far
     * enough to contain a source boundary that falls within that one-second
     * timestamp resolution.
     *
     * @param list<ActivityDetailItem> $details
     * @param list<LapItem>            $laps
     * @param list<SessionItem>        $sessions
     */
    public function resolve(
        ActivityLifecycleItem $terminalFinish,
        array $details,
        array $laps,
        array $sessions,
        ?Instant $lastObservationAt,
        ?Instant $lastLifecycleAt,
    ): FitTerminalFinishResolution {
        $candidate = $terminalFinish->occurredAt;
        $boundaryType = 'timer_event';

        if (
            null !== $lastObservationAt
            && $lastObservationAt->isAfter($candidate)
        ) {
            $candidate = $lastObservationAt;
            $boundaryType = 'observation';
        }

        foreach ($details as $detail) {
            $finishedAt = $detail->detail->interval()->finishedAt;

            if ($finishedAt->isAfter($candidate)) {
                $candidate = $finishedAt;
                $boundaryType = 'activity_detail';
            }
        }

        foreach ($laps as $lap) {
            if ($lap->lap->finishedAt->isAfter($candidate)) {
                $candidate = $lap->lap->finishedAt;
                $boundaryType = 'lap';
            }
        }

        foreach ($sessions as $session) {
            if ($session->session->finishedAt->isAfter($candidate)) {
                $candidate = $session->session->finishedAt;
                $boundaryType = 'session';
            }
        }

        if (
            null !== $lastLifecycleAt
            && $lastLifecycleAt->isAfter($candidate)
        ) {
            $candidate = $lastLifecycleAt;
            $boundaryType = 'event';
        }

        if (!$candidate->isAfter($terminalFinish->occurredAt)) {
            return new FitTerminalFinishResolution($terminalFinish);
        }

        $driftMicroseconds = Duration::between(
            $terminalFinish->occurredAt,
            $candidate,
        )->toMicroseconds();

        if (
            $driftMicroseconds
            >= TemporalResolution::Second->value
        ) {
            return new FitTerminalFinishResolution($terminalFinish);
        }

        $warning = new ActivityImportWarning(
            code: ActivityImportWarningCode::TerminalTimerBoundaryResolutionAdjusted,
            message: 'Terminal FIT timer stop was extended within its source timestamp resolution to contain a later activity boundary.',
            context: [
                'boundaryType' => $boundaryType,
                'terminalFinishedAt' => self::formatInstant(
                    $terminalFinish->occurredAt,
                ),
                'adjustedFinishedAt' => self::formatInstant(
                    $candidate,
                ),
                'driftMicroseconds' => $driftMicroseconds,
                'resolutionMicroseconds' => TemporalResolution::Second->value,
            ],
        );

        return new FitTerminalFinishResolution(
            finish: new ActivityLifecycleItem(
                action: ActivityLifecycleAction::Finish,
                occurredAt: $candidate,
            ),
            warning: $warning,
        );
    }

    private static function formatInstant(Instant $instant): string
    {
        return $instant
            ->toDateTimeImmutable()
            ->format('Y-m-d\TH:i:s.uP');
    }
}
