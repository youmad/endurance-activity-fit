<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\ActivityFit\Mapper\FitTerminalFinishResolver;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class FitTerminalFinishResolverTest extends TestCase
{
    /** @return iterable<string, array{?string, bool}> */
    public static function boundaries(): iterable
    {
        yield 'no observation' => [null, false];
        yield 'earlier' => ['09.999999', false];
        yield 'equal' => ['10.000000', false];
        yield 'one microsecond' => ['10.000001', true];
        yield 'less than one second' => ['10.999999', true];
        yield 'exactly one second' => ['11.000000', false];
        yield 'more than one second' => ['11.000001', false];
    }

    #[DataProvider('boundaries')]
    public function testExtendsOnlyWithinSourceResolution(?string $boundary, bool $adjusted): void
    {
        $finish = new ActivityLifecycleItem(ActivityLifecycleAction::Finish, $this->at('10.000000'));
        $observation = null === $boundary ? null : $this->at($boundary);
        $resolver = new FitTerminalFinishResolver();
        $result = $resolver->resolve($finish, [], [], [], $observation, null);

        if ($adjusted) {
            self::assertSame($observation, $result->finish->occurredAt);
            self::assertSame(ActivityLifecycleAction::Finish, $result->finish->action);
            self::assertNotNull($result->warning);
            self::assertSame(ActivityImportWarningCode::TerminalTimerBoundaryResolutionAdjusted, $result->warning->code);
            self::assertSame('observation', $result->warning->context['boundaryType']);
            self::assertSame(1_000_000, $result->warning->context['resolutionMicroseconds']);
        } else {
            self::assertSame($finish, $result->finish);
            self::assertNull($result->warning);
        }

        // Reusing the resolver must not carry over a prior result or warning.
        $unchanged = $resolver->resolve($finish, [], [], [], null, null);
        self::assertSame($finish, $unchanged->finish);
        self::assertNull($unchanged->warning);
    }

    public function testEqualBoundariesKeepTheExistingSourcePriority(): void
    {
        $start = $this->at('00.000000');
        $boundary = $this->at('10.500000');
        $finish = new ActivityLifecycleItem(ActivityLifecycleAction::Finish, $this->at('10.000000'));
        $result = (new FitTerminalFinishResolver())->resolve(
            $finish,
            [],
            [new LapItem(Lap::create($start, $boundary, Duration::zero()))],
            [new SessionItem(ActivitySession::create($start, $boundary, Duration::zero()))],
            $boundary,
            $boundary,
        );
        self::assertNotNull($result->warning);
        self::assertSame('observation', $result->warning->context['boundaryType']);
        self::assertSame(500_000, $result->warning->context['driftMicroseconds']);
    }

    public function testLaterEventBeyondResolutionPreventsPartialAdjustment(): void
    {
        $finish = new ActivityLifecycleItem(ActivityLifecycleAction::Finish, $this->at('10.000000'));
        $result = (new FitTerminalFinishResolver())->resolve(
            $finish, [], [], [], $this->at('10.500000'), $this->at('11.000000'),
        );
        self::assertSame($finish, $result->finish);
        self::assertNull($result->warning);
    }

    private function at(string $seconds): Instant
    {
        return Instant::fromDateTimeImmutable(new \DateTimeImmutable('2026-09-27T10:00:'.$seconds.'Z'));
    }
}
