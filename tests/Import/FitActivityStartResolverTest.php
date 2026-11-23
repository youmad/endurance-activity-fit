<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Tests\Import;

use Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Endurance\Activity\Application\Import\LapItem;
use Endurance\Activity\Application\Import\SessionItem;
use Endurance\Activity\Session\ActivitySession;
use Endurance\Activity\ValueObject\Lap;
use Endurance\ActivityFit\Import\FitActivityStartResolver;
use Endurance\Foundation\ValueObject\Duration;
use Endurance\Foundation\ValueObject\Instant;
use PHPUnit\Framework\TestCase;

final class FitActivityStartResolverTest extends TestCase
{
    public function testSessionStartCanPrecedeInitialTimerStart(): void
    {
        $resolver = new FitActivityStartResolver();
        $timer = new ActivityLifecycleItem(ActivityLifecycleAction::TimerStart, $this->instant(3));
        $resolver->observe($timer);
        $resolver->observe(new SessionItem(ActivitySession::create(
            startedAt: $this->instant(0),
            finishedAt: $this->instant(30),
            timerDuration: Duration::fromMicroseconds(27_000_000),
        )));

        self::assertTrue($resolver->resolve()->equals($this->instant(0)));
        self::assertTrue($timer->occurredAt->equals($this->instant(3)));
    }

    public function testEarlierTimerStartRemainsTheActivityStart(): void
    {
        $resolver = new FitActivityStartResolver();
        $resolver->observe(new SessionItem(ActivitySession::create(
            startedAt: $this->instant(3),
            finishedAt: $this->instant(30),
            timerDuration: Duration::fromMicroseconds(27_000_000),
        )));
        $resolver->observe(new ActivityLifecycleItem(ActivityLifecycleAction::TimerStart, $this->instant(0)));
        self::assertTrue($resolver->resolve()->equals($this->instant(0)));
    }

    public function testEarlierLapDoesNotWidenAnExplicitTimerInterval(): void
    {
        $resolver = new FitActivityStartResolver();
        $resolver->observe(new LapItem(Lap::create(
            startedAt: $this->instant(0),
            finishedAt: $this->instant(30),
            timerDuration: Duration::fromMicroseconds(27_000_000),
        )));
        $resolver->observe(new ActivityLifecycleItem(ActivityLifecycleAction::TimerStart, $this->instant(3)));
        self::assertTrue($resolver->resolve()->equals($this->instant(3)));
    }

    private function instant(int $second): Instant
    {
        return Instant::fromDateTimeImmutable(
            (new \DateTimeImmutable('2026-01-15T10:30:00Z'))->modify(sprintf('+%d seconds', $second)),
        );
    }
}
