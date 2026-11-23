<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Tests\Mapper;

use Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Endurance\Activity\Application\Import\SessionItem;
use Endurance\Activity\Session\ActivitySession;
use Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Endurance\ActivityFit\Mapper\FitActivitySummaryRecovery;
use Endurance\ActivityFit\Value\FitActivitySummarySource;
use Endurance\Fit\Decoder\FitDecoder;
use Endurance\Fit\Profile\InMemoryFitProfileRegistry;
use Endurance\Fit\Profile\InMemoryFitTypeRegistry;
use Endurance\Fit\Profile\MessageProfile;
use Endurance\Fit\Raw\FitArchitecture;
use Endurance\Fit\Raw\MessageDefinition;
use Endurance\Fit\Raw\RawDataMessage;
use Endurance\Fit\Unified\UnifiedDataMessage;
use Endurance\Foundation\ValueObject\Duration;
use Endurance\Foundation\ValueObject\Instant;
use Endurance\Foundation\ValueObject\TemporalResolution;
use PHPUnit\Framework\TestCase;

final class FitActivitySummaryRecoveryTest extends TestCase
{
    public function testRecoversMissingTimerAndSessionCountFromSessions(): void
    {
        $startedAt = $this->instant('1989-12-31T00:20:00Z');
        $finishedAt = $this->instant('1989-12-31T00:30:00Z');
        $timerDuration = Duration::fromMicroseconds(559_000_000);
        $result = (new FitActivitySummaryRecovery())->recover(
            source: new FitActivitySummarySource(
                message: $this->activityMessage(),
                reportedAt: $this->instant(
                    '1989-12-31T00:30:01Z',
                ),
                timerDuration: null,
                sessionCount: null,
                type: 'manual',
                timelineResolution: TemporalResolution::Second,
                localTimeOffsetSeconds: -12_600,
            ),
            sessions: [new SessionItem(
                ActivitySession::create(
                    startedAt: $startedAt,
                    finishedAt: $finishedAt,
                    timerDuration: $timerDuration,
                ),
            )],
        );

        self::assertSame(
            1,
            $result->activitySummary->summary->sessionCount,
        );
        self::assertSame(
            559_000_000,
            $result->activitySummary->summary
                ->timerDuration
                ->toMicroseconds(),
        );
        self::assertSame(
            TemporalResolution::Second,
            $result->activitySummary->timelineResolution,
        );
        self::assertSame(
            -12_600,
            $result->activitySummary->summary
                ->localTimeOffsetSeconds,
        );
        self::assertTrue(
            $result->activitySummary->summary->reportedAt->equals(
                $this->instant('1989-12-31T00:30:01Z'),
            ),
        );
        self::assertCount(1, $result->warnings);
        self::assertSame(
            ActivityImportWarningCode::ActivitySummaryFieldsRecovered,
            $result->warnings[0]->code,
        );
        self::assertSame(
            [
                'sessionCountRecovered' => true,
                'timerDurationRecovered' => true,
                'recoveredSessionCount' => 1,
                'recoveredTimerMicroseconds' => 559_000_000,
            ],
            $result->warnings[0]->context,
        );
    }

    public function testAdjustsActivityTimestampBeforeFinalSessionBoundary(): void
    {
        $timerDuration = Duration::fromMicroseconds(600_000_000);
        $result = (new FitActivitySummaryRecovery())->recover(
            source: new FitActivitySummarySource(
                message: $this->activityMessage(),
                reportedAt: $this->instant(
                    '1989-12-31T00:10:00Z',
                ),
                timerDuration: $timerDuration,
                sessionCount: 1,
                type: 'manual',
                timelineResolution: TemporalResolution::Second,
                localTimeOffsetSeconds: 3_600,
            ),
            sessions: [new SessionItem(
                ActivitySession::create(
                    startedAt: $this->instant(
                        '1989-12-31T00:20:00Z',
                    ),
                    finishedAt: $this->instant(
                        '1989-12-31T00:30:00Z',
                    ),
                    timerDuration: $timerDuration,
                ),
            )],
        );

        self::assertTrue(
            $result->activitySummary->summary->reportedAt->equals(
                $this->instant('1989-12-31T00:30:00Z'),
            ),
        );
        self::assertSame(
            3_600,
            $result->activitySummary->summary->localTimeOffsetSeconds,
        );
        self::assertCount(1, $result->warnings);
        self::assertSame(
            ActivityImportWarningCode::ActivitySummaryTimestampAdjusted,
            $result->warnings[0]->code,
        );
        self::assertSame(
            [
                'sourceTimestamp' => '1989-12-31T00:10:00.000000+00:00',
                'adjustedReportedAt' => '1989-12-31T00:30:00.000000+00:00',
            ],
            $result->warnings[0]->context,
        );
    }

    public function testPreservesDecodedSessionsWhenDeclaredSessionCountDiffers(): void
    {
        $timerDuration = Duration::fromMicroseconds(559_000_000);
        $result = (new FitActivitySummaryRecovery())->recover(
            source: new FitActivitySummarySource(
                message: $this->activityMessage(),
                reportedAt: $this->instant(
                    '1989-12-31T00:30:01Z',
                ),
                timerDuration: $timerDuration,
                sessionCount: 2,
                type: 'manual',
                timelineResolution: TemporalResolution::Second,
            ),
            sessions: [new SessionItem(
                ActivitySession::create(
                    startedAt: $this->instant(
                        '1989-12-31T00:20:00Z',
                    ),
                    finishedAt: $this->instant(
                        '1989-12-31T00:30:00Z',
                    ),
                    timerDuration: $timerDuration,
                ),
            )],
        );

        self::assertSame(
            1,
            $result->activitySummary->summary->sessionCount,
        );
        self::assertCount(1, $result->warnings);
        self::assertSame(
            ActivityImportWarningCode::ActivitySessionCountMismatch,
            $result->warnings[0]->code,
        );
        self::assertSame(
            [
                'declaredSessionCount' => 2,
                'decodedSessionCount' => 1,
            ],
            $result->warnings[0]->context,
        );
    }

    public function testDoesNotInventMissingFieldsWithoutSessions(): void
    {
        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage(
            'has no usable num_sessions field',
        );

        (new FitActivitySummaryRecovery())->recover(
            source: new FitActivitySummarySource(
                message: $this->activityMessage(),
                reportedAt: $this->instant(
                    '1989-12-31T00:30:01Z',
                ),
                timerDuration: null,
                sessionCount: null,
                type: 'manual',
                timelineResolution: TemporalResolution::Second,
            ),
            sessions: [],
        );
    }

    private function activityMessage(): UnifiedDataMessage
    {
        $profile = MessageProfile::create(
            globalMessageNumber: 34,
            name: 'activity',
        );
        $definition = MessageDefinition::create(
            localMessageNumber: 0,
            architecture: FitArchitecture::LittleEndian,
            globalMessageNumber: 34,
        );

        return (new FitDecoder(
            profiles: new InMemoryFitProfileRegistry($profile),
            types: new InMemoryFitTypeRegistry(),
        ))->decode(
            RawDataMessage::create(
                sequenceNumber: 3,
                byteOffset: 75,
                recordHeaderByte: 0x00,
                definition: $definition,
            ),
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }
}
