<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivitySummaryItem;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\Summary\ActivitySummary;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\Activity\ValueObject\SummaryAdjacencyPolicy;
use Youmad\Endurance\ActivityFit\Mapper\FitMissingSummaryRecovery;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final class FitMissingSummaryRecoveryTest extends TestCase
{
    public function testRecoversSingleSessionAndLapFromObservationRange(): void
    {
        $startedAt = $this->instant('2022-06-22T23:08:37Z');
        $finishedAt = $this->instant('2022-06-22T23:41:35Z');

        $recovered = (new FitMissingSummaryRecovery())->recover(
            activitySummary: $this->summary(
                timerMicroseconds: 1_978_000_000,
                sessionCount: 1,
            ),
            laps: [],
            sessions: [],
            firstObservationAt: $startedAt,
            lastObservationAt: $finishedAt,
        );

        self::assertCount(1, $recovered->laps);
        self::assertCount(1, $recovered->sessions);

        $lap = $recovered->laps[0]->lap;
        $session = $recovered->sessions[0]->session;
        self::assertSame(SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds, $session->adjacencyPolicy);
        self::assertSame($session->adjacencyPolicy, $lap->adjacencyPolicy);

        self::assertTrue($lap->startedAt->equals($startedAt));
        self::assertTrue($lap->finishedAt->equals($finishedAt));
        self::assertSame(
            1_978_000_000,
            $lap->timerDuration->toMicroseconds(),
        );
        self::assertSame(
            TemporalResolution::Second,
            $lap->timelineResolution,
        );

        self::assertTrue($session->startedAt->equals($startedAt));
        self::assertTrue($session->finishedAt->equals($finishedAt));
        self::assertSame(
            1_978_000_000,
            $session->timerDuration->toMicroseconds(),
        );
        self::assertSame(
            TemporalResolution::Second,
            $session->timelineResolution,
        );
        self::assertNull($session->sport);
        self::assertNull($session->subSport);
    }

    public function testRecoveredLapInheritsExplicitPolicyInsteadOfInferringItFromPrecision(): void
    {
        $start = $this->instant('2022-06-22T23:08:37Z');
        $end = $this->instant('2022-06-22T23:41:35Z');
        $session = ActivitySession::create(
            $start,
            $end,
            Duration::between($start, $end),
            timelineResolution: TemporalResolution::Second,
            adjacencyPolicy: SummaryAdjacencyPolicy::NonOverlapping,
        );
        $recovered = (new FitMissingSummaryRecovery())->recover(
            activitySummary: null,
            laps: [],
            sessions: [new SessionItem($session)],
            firstObservationAt: $start,
            lastObservationAt: $end,
        );
        self::assertCount(1, $recovered->laps);
        self::assertSame(SummaryAdjacencyPolicy::NonOverlapping, $recovered->laps[0]->lap->adjacencyPolicy);
        self::assertSame(TemporalResolution::Second, $recovered->laps[0]->lap->timelineResolution);
    }

    public function testRecoversSingleSessionAndLapWithoutActivitySummary(): void
    {
        $startedAt = $this->instant('2022-06-22T23:08:37Z');
        $finishedAt = $this->instant('2022-06-22T23:41:35Z');

        $recovered = (new FitMissingSummaryRecovery())->recover(
            activitySummary: null,
            laps: [],
            sessions: [],
            firstObservationAt: $startedAt,
            lastObservationAt: $finishedAt,
        );

        self::assertCount(1, $recovered->sessions);
        self::assertCount(1, $recovered->laps);
        self::assertTrue(
            $recovered->sessions[0]->session->startedAt->equals($startedAt),
        );
        self::assertTrue(
            $recovered->sessions[0]->session->finishedAt->equals($finishedAt),
        );
        self::assertSame(
            1_978_000_000,
            $recovered->sessions[0]
                ->session
                ->timerDuration
                ->toMicroseconds(),
        );
        self::assertSame(
            TemporalResolution::Second,
            $recovered->sessions[0]->session->timelineResolution,
        );
        self::assertSame(
            1_978_000_000,
            $recovered->laps[0]
                ->lap
                ->timerDuration
                ->toMicroseconds(),
        );
        self::assertSame(
            TemporalResolution::Second,
            $recovered->laps[0]->lap->timelineResolution,
        );
    }

    public function testRecoveredSummaryDoesNotStartBeforeExplicitActivityStart(): void
    {
        $firstObservationAt = $this->instant('2022-06-22T23:08:30Z');
        $activityStartedAt = $this->instant('2022-06-22T23:08:37Z');
        $finishedAt = $this->instant('2022-06-22T23:41:35Z');

        $recovered = (new FitMissingSummaryRecovery())->recover(
            activitySummary: $this->summary(
                timerMicroseconds: 1_978_000_000,
                sessionCount: 1,
            ),
            laps: [],
            sessions: [],
            firstObservationAt: $firstObservationAt,
            lastObservationAt: $finishedAt,
            explicitTimerStartAt: $activityStartedAt,
        );

        self::assertCount(1, $recovered->sessions);
        self::assertCount(1, $recovered->laps);
        self::assertTrue(
            $recovered->sessions[0]
                ->session
                ->startedAt
                ->equals($activityStartedAt),
        );
        self::assertTrue(
            $recovered->laps[0]
                ->lap
                ->startedAt
                ->equals($activityStartedAt),
        );
        self::assertSame(
            1_978_000_000,
            $recovered->sessions[0]
                ->session
                ->timerDuration
                ->toMicroseconds(),
        );
    }

    public function testDoesNotRecoverSummaryWhenAllObservationsPrecedeExplicitActivityStart(): void
    {
        $recovered = (new FitMissingSummaryRecovery())->recover(
            activitySummary: $this->summary(
                timerMicroseconds: 1_978_000_000,
                sessionCount: 1,
            ),
            laps: [],
            sessions: [],
            firstObservationAt: $this->instant(
                '2022-06-22T23:08:30Z',
            ),
            lastObservationAt: $this->instant(
                '2022-06-22T23:08:36Z',
            ),
            explicitTimerStartAt: $this->instant(
                '2022-06-22T23:08:37Z',
            ),
        );

        self::assertSame([], $recovered->sessions);
        self::assertSame([], $recovered->laps);
        self::assertSame([], $recovered->warnings);
    }

    public function testPreservesExistingLapWhileRecoveringSession(): void
    {
        $startedAt = $this->instant('2022-06-22T23:08:37Z');
        $finishedAt = $this->instant('2022-06-22T23:41:35Z');
        $existingLap = new LapItem(
            Lap::create(
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                timerDuration: Duration::fromMicroseconds(
                    1_900_000_000,
                ),
            ),
        );

        $recovered = (new FitMissingSummaryRecovery())->recover(
            activitySummary: $this->summary(
                timerMicroseconds: 1_978_000_000,
                sessionCount: 1,
            ),
            laps: [$existingLap],
            sessions: [],
            firstObservationAt: $startedAt,
            lastObservationAt: $finishedAt,
        );

        self::assertSame([$existingLap], $recovered->laps);
        self::assertCount(1, $recovered->sessions);
        self::assertSame(
            TemporalResolution::Second,
            $recovered->sessions[0]->session->timelineResolution,
        );
    }

    public function testRecoversLapFromExistingSingleSession(): void
    {
        $startedAt = $this->instant('2024-07-14T00:03:58Z');
        $finishedAt = $this->instant('2024-07-14T01:07:34Z');
        $session = new SessionItem(
            ActivitySession::create(
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                timerDuration: Duration::fromMicroseconds(
                    3_816_000_000,
                ),
                timelineResolution: TemporalResolution::Second,
            ),
        );

        $recovered = (new FitMissingSummaryRecovery())->recover(
            activitySummary: $this->summary(
                timerMicroseconds: 3_825_000_000,
                sessionCount: 1,
            ),
            laps: [],
            sessions: [$session],
            firstObservationAt: $startedAt,
            lastObservationAt: $finishedAt,
        );

        self::assertSame([$session], $recovered->sessions);
        self::assertCount(1, $recovered->laps);
        self::assertSame(
            3_816_000_000,
            $recovered->laps[0]
                ->lap
                ->timerDuration
                ->toMicroseconds(),
        );
        self::assertSame(
            TemporalResolution::Second,
            $recovered->laps[0]->lap->timelineResolution,
        );
    }

    public function testDoesNotRecoverAmbiguousMultipleSessions(): void
    {
        $recovered = (new FitMissingSummaryRecovery())->recover(
            activitySummary: $this->summary(
                timerMicroseconds: 1_978_000_000,
                sessionCount: 2,
            ),
            laps: [],
            sessions: [],
            firstObservationAt: $this->instant(
                '2022-06-22T23:08:37Z',
            ),
            lastObservationAt: $this->instant(
                '2022-06-22T23:41:35Z',
            ),
        );

        self::assertSame([], $recovered->laps);
        self::assertSame([], $recovered->sessions);
    }

    private function summary(
        int $timerMicroseconds,
        int $sessionCount,
    ): ActivitySummaryItem {
        return new ActivitySummaryItem(
            summary: ActivitySummary::create(
                reportedAt: $this->instant(
                    '2022-06-22T23:41:35Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    $timerMicroseconds,
                ),
                sessionCount: $sessionCount,
            ),
            timelineResolution: TemporalResolution::Second,
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }
}
