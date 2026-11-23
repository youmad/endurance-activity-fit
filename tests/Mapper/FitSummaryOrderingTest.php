<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Tests\Mapper;

use Endurance\Activity\Application\Import\ActivityImportItem;
use Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Endurance\Activity\Application\Import\ActivitySummaryItem;
use Endurance\Activity\Application\Import\LapItem;
use Endurance\Activity\Application\Import\ObservationItem;
use Endurance\Activity\Application\Import\SessionItem;
use Endurance\Activity\Session\ActivitySession;
use Endurance\Activity\Summary\ActivitySummary;
use Endurance\Activity\Telemetry\ActivityObservation;
use Endurance\Activity\Telemetry\PositionMeasurement;
use Endurance\Activity\ValueObject\Lap;
use Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Endurance\ActivityFit\Mapper\FitMessageMapper;
use Endurance\Fit\Decoder\FitDecoder;
use Endurance\Fit\Profile\InMemoryFitProfileRegistry;
use Endurance\Fit\Profile\InMemoryFitTypeRegistry;
use Endurance\Fit\Profile\MessageProfile;
use Endurance\Fit\Raw\FitArchitecture;
use Endurance\Fit\Raw\MessageDefinition;
use Endurance\Fit\Raw\RawDataMessage;
use Endurance\Fit\Unified\UnifiedDataMessage;
use Endurance\Foundation\ValueObject\Coordinate;
use Endurance\Foundation\ValueObject\Duration;
use Endurance\Foundation\ValueObject\Instant;
use PHPUnit\Framework\TestCase;

final class FitSummaryOrderingTest extends TestCase
{
    public function testSummaryFirstMessagesAreEmittedAfterChronologicalData(): void
    {
        $activitySummary = new ActivitySummaryItem(
            ActivitySummary::create(
                reportedAt: $this->instant(
                    '2026-01-15T12:00:00Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    1_700_000_000,
                ),
                sessionCount: 1,
                type: 'manual',
            ),
        );

        $session = new SessionItem(
            ActivitySession::create(
                startedAt: $this->instant(
                    '2026-01-15T10:00:00Z',
                ),
                finishedAt: $this->instant(
                    '2026-01-15T10:30:00Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    1_700_000_000,
                ),
            ),
        );

        $lap = new LapItem(
            Lap::create(
                startedAt: $this->instant(
                    '2026-01-15T10:00:00Z',
                ),
                finishedAt: $this->instant(
                    '2026-01-15T10:30:00Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    1_700_000_000,
                ),
            ),
        );

        $observation = new ObservationItem(
            ActivityObservation::create(
                $this->instant(
                    '2026-01-15T10:15:00Z',
                ),
                new PositionMeasurement(
                    new Coordinate(
                        latitude: 59.4369,
                        longitude: 24.7535,
                    ),
                ),
            ),
        );

        $finish = new ActivityLifecycleItem(
            action: ActivityLifecycleAction::Finish,
            occurredAt: $this->instant(
                '2026-01-15T10:30:00Z',
            ),
        );
        $terminalPause = new ActivityLifecycleItem(
            action: ActivityLifecycleAction::Pause,
            occurredAt: $finish->occurredAt,
        );

        $itemsByMessage = [
            34 => [$activitySummary],
            18 => [$session],
            19 => [$lap],
            20 => [$observation],
            // FitTimerEventMessageMapper projects terminal stop_all as this
            // companion Pause followed by the terminal Finish candidate.
            21 => [$terminalPause, $finish],
        ];

        $mapper = new FitActivityImportItemMapper(
            mappers: [
                new class($itemsByMessage) implements FitMessageMapper {
                    /**
                     * @param array<int, list<ActivityImportItem>> $items
                     */
                    public function __construct(
                        private readonly array $items,
                    ) {
                    }

                    public function supports(
                        UnifiedDataMessage $message,
                    ): bool {
                        return isset(
                            $this->items[
                                $message->globalMessageNumber()
                            ],
                        );
                    }

                    public function map(
                        UnifiedDataMessage $message,
                    ): array {
                        return $this->items[
                            $message->globalMessageNumber()
                        ];
                    }
                },
            ],
        );

        $mapped = iterator_to_array(
            $mapper->mapStream(
                [
                    $this->message(34, 'activity'),
                    $this->message(18, 'session'),
                    $this->message(19, 'lap'),
                    $this->message(20, 'record'),
                    $this->message(21, 'event'),
                ],
            ),
            false,
        );

        self::assertSame(
            [
                ObservationItem::class,
                LapItem::class,
                SessionItem::class,
                ActivitySummaryItem::class,
            ],
            array_map(
                static fn (ActivityImportItem $item): string => $item::class,
                $mapped,
            ),
        );

        self::assertFalse(
            in_array(
                $finish,
                $mapped,
                true,
            ),
        );
        self::assertFalse(
            in_array(
                $terminalPause,
                $mapped,
                true,
            ),
        );
    }

    public function testTerminalFinishIsPreservedWithoutActivitySummary(): void
    {
        $finish = new ActivityLifecycleItem(
            action: ActivityLifecycleAction::Finish,
            occurredAt: $this->instant(
                '2026-01-15T10:30:00Z',
            ),
        );

        $mapper = new FitActivityImportItemMapper(
            mappers: [
                new class($finish) implements FitMessageMapper {
                    public function __construct(
                        private readonly ActivityLifecycleItem $finish,
                    ) {
                    }

                    public function supports(
                        UnifiedDataMessage $message,
                    ): bool {
                        return 21
                            === $message->globalMessageNumber();
                    }

                    public function map(
                        UnifiedDataMessage $message,
                    ): array {
                        return [$this->finish];
                    }
                },
            ],
        );

        self::assertSame(
            [$finish],
            iterator_to_array(
                $mapper->mapStream(
                    [$this->message(21, 'event')],
                ),
                false,
            ),
        );
    }

    public function testMissingSessionAndLapAreRecoveredWithoutActivitySummary(): void
    {
        $startedAt = $this->instant('2026-01-15T10:00:00Z');
        $finishedAt = $this->instant('2026-01-15T10:10:00Z');

        $firstObservation = new ObservationItem(
            ActivityObservation::create(
                $startedAt,
                new PositionMeasurement(
                    new Coordinate(
                        latitude: 59.4369,
                        longitude: 24.7535,
                    ),
                ),
            ),
        );
        $lastObservation = new ObservationItem(
            ActivityObservation::create(
                $finishedAt,
                new PositionMeasurement(
                    new Coordinate(
                        latitude: 59.4370,
                        longitude: 24.7536,
                    ),
                ),
            ),
        );
        $finish = new ActivityLifecycleItem(
            action: ActivityLifecycleAction::Finish,
            occurredAt: $finishedAt,
        );

        $itemsByMessage = [
            22 => [$firstObservation],
            24 => [$lastObservation],
            21 => [$finish],
        ];

        $mapper = new FitActivityImportItemMapper(
            mappers: [
                new class($itemsByMessage) implements FitMessageMapper {
                    /**
                     * @param array<int, list<ActivityImportItem>> $items
                     */
                    public function __construct(
                        private readonly array $items,
                    ) {
                    }

                    public function supports(
                        UnifiedDataMessage $message,
                    ): bool {
                        return isset(
                            $this->items[
                                $message->globalMessageNumber()
                            ],
                        );
                    }

                    public function map(
                        UnifiedDataMessage $message,
                    ): array {
                        return $this->items[
                            $message->globalMessageNumber()
                        ];
                    }
                },
            ],
        );

        $mapped = iterator_to_array(
            $mapper->mapStream(
                [
                    $this->message(22, 'observation_head'),
                    $this->message(24, 'observation_tail'),
                    $this->message(21, 'event'),
                ],
            ),
            false,
        );

        self::assertCount(5, $mapped);
        // File-buffer replay preserves values, not object identity.
        self::assertEquals($firstObservation, $mapped[0]);
        self::assertEquals($lastObservation, $mapped[1]);
        self::assertSame($finish, $mapped[2]);
        self::assertInstanceOf(LapItem::class, $mapped[3]);
        self::assertInstanceOf(SessionItem::class, $mapped[4]);

        self::assertTrue($mapped[3]->lap->startedAt->equals($startedAt));
        self::assertTrue($mapped[3]->lap->finishedAt->equals($finishedAt));
        self::assertSame(
            600_000_000,
            $mapped[3]->lap->timerDuration->toMicroseconds(),
        );
        self::assertTrue(
            $mapped[4]->session->startedAt->equals($startedAt),
        );
        self::assertTrue(
            $mapped[4]->session->finishedAt->equals($finishedAt),
        );
        self::assertSame(
            600_000_000,
            $mapped[4]->session->timerDuration->toMicroseconds(),
        );

        self::assertSame(
            [
                ActivityImportWarningCode::MissingSessionRecovered,
                ActivityImportWarningCode::MissingLapRecovered,
            ],
            array_map(
                static fn ($warning): ActivityImportWarningCode => $warning->code,
                $mapper->warnings(),
            ),
        );
    }

    public function testTerminalFinishIsExtendedWithinSecondResolutionToContainSessionBoundary(): void
    {
        $finish = new ActivityLifecycleItem(
            action: ActivityLifecycleAction::Finish,
            occurredAt: $this->instant(
                '2026-01-15T10:30:10.000000Z',
            ),
        );

        $session = new SessionItem(
            ActivitySession::create(
                startedAt: $this->instant(
                    '2026-01-15T10:00:00Z',
                ),
                finishedAt: $this->instant(
                    '2026-01-15T10:30:10.500000Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    1_800_000_000,
                ),
            ),
        );

        $itemsByMessage = [
            21 => [$finish],
            18 => [$session],
        ];

        $mapper = new FitActivityImportItemMapper(
            mappers: [
                new class($itemsByMessage) implements FitMessageMapper {
                    /**
                     * @param array<int, list<ActivityImportItem>> $items
                     */
                    public function __construct(
                        private readonly array $items,
                    ) {
                    }

                    public function supports(
                        UnifiedDataMessage $message,
                    ): bool {
                        return isset(
                            $this->items[
                                $message->globalMessageNumber()
                            ],
                        );
                    }

                    public function map(
                        UnifiedDataMessage $message,
                    ): array {
                        return $this->items[
                            $message->globalMessageNumber()
                        ];
                    }
                },
            ],
        );

        $mapped = iterator_to_array(
            $mapper->mapStream(
                [
                    $this->message(21, 'event'),
                    $this->message(18, 'session'),
                ],
            ),
            false,
        );

        self::assertCount(2, $mapped);
        self::assertInstanceOf(ActivityLifecycleItem::class, $mapped[0]);
        self::assertSame(
            '2026-01-15T10:30:10.500000+00:00',
            $mapped[0]
                ->occurredAt
                ->toDateTimeImmutable()
                ->format('Y-m-d\TH:i:s.uP'),
        );
        self::assertSame($session, $mapped[1]);

        $warnings = $mapper->warnings();
        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::TerminalTimerBoundaryResolutionAdjusted,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'boundaryType' => 'session',
                'terminalFinishedAt' => '2026-01-15T10:30:10.000000+00:00',
                'adjustedFinishedAt' => '2026-01-15T10:30:10.500000+00:00',
                'driftMicroseconds' => 500_000,
                'resolutionMicroseconds' => 1_000_000,
            ],
            $warnings[0]->context,
        );
    }

    public function testTerminalFinishIsNotExtendedByOneSecondOrMore(): void
    {
        $finish = new ActivityLifecycleItem(
            action: ActivityLifecycleAction::Finish,
            occurredAt: $this->instant(
                '2026-01-15T10:30:10Z',
            ),
        );

        $session = new SessionItem(
            ActivitySession::create(
                startedAt: $this->instant(
                    '2026-01-15T10:00:00Z',
                ),
                finishedAt: $this->instant(
                    '2026-01-15T10:30:11Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    1_800_000_000,
                ),
            ),
        );

        $itemsByMessage = [
            21 => [$finish],
            18 => [$session],
        ];

        $mapper = new FitActivityImportItemMapper(
            mappers: [
                new class($itemsByMessage) implements FitMessageMapper {
                    /**
                     * @param array<int, list<ActivityImportItem>> $items
                     */
                    public function __construct(
                        private readonly array $items,
                    ) {
                    }

                    public function supports(
                        UnifiedDataMessage $message,
                    ): bool {
                        return isset(
                            $this->items[
                                $message->globalMessageNumber()
                            ],
                        );
                    }

                    public function map(
                        UnifiedDataMessage $message,
                    ): array {
                        return $this->items[
                            $message->globalMessageNumber()
                        ];
                    }
                },
            ],
        );

        $mapped = iterator_to_array(
            $mapper->mapStream(
                [
                    $this->message(21, 'event'),
                    $this->message(18, 'session'),
                ],
            ),
            false,
        );

        self::assertCount(2, $mapped);
        self::assertSame($finish, $mapped[0]);
        self::assertSame($session, $mapped[1]);
        self::assertSame([], $mapper->warnings());
    }

    private function message(
        int $globalMessageNumber,
        string $name,
    ): UnifiedDataMessage {
        $profile = MessageProfile::create(
            globalMessageNumber: $globalMessageNumber,
            name: $name,
        );

        $definition = MessageDefinition::create(
            localMessageNumber: 0,
            architecture: FitArchitecture::LittleEndian,
            globalMessageNumber: $globalMessageNumber,
        );

        return (
            new FitDecoder(
                profiles: new InMemoryFitProfileRegistry(
                    $profile,
                ),
                types: new InMemoryFitTypeRegistry(),
            )
        )->decode(
            RawDataMessage::create(
                sequenceNumber: 0,
                byteOffset: 0,
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
