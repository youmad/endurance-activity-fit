<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Youmad\Endurance\Activity\Application\Import\ActivitySummaryItem;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\Activity\Detail\ActivityInterval;
use Youmad\Endurance\Activity\Detail\Pool\PoolLength;
use Youmad\Endurance\Activity\Detail\Pool\PoolLengthType;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\Summary\ActivitySummary;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\PositionMeasurement;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\ActivityFit\Import\FitActivityImportWarningCollector;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportWarningDetector;
use Youmad\Endurance\ActivityFit\Mapper\FitMessageMapper;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Profile\InMemoryFitProfileRegistry;
use Youmad\Endurance\Fit\Profile\InMemoryFitTypeRegistry;
use Youmad\Endurance\Fit\Profile\MessageProfile;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\MessageDefinition;
use Youmad\Endurance\Fit\Raw\RawDataMessage;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final class FitActivityImportItemMapperWarningTest extends TestCase
{
    public function testCollectsWarningForSkippedRecordWithoutTimestamp(): void
    {
        $mapper = new FitActivityImportItemMapper(mappers: []);

        self::assertSame(
            [],
            $mapper->map($this->message(20, 'record')),
        );

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::RecordWithoutTimestampSkipped,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'sequence' => 0,
                'byteOffset' => 0,
            ],
            $warnings[0]->context,
        );
    }

    public function testResetsWarningsForEveryStream(): void
    {
        $collector = new FitActivityImportWarningCollector();
        $collector->add(
            new ActivityImportWarning(
                code: ActivityImportWarningCode::UnknownActivityEvent,
                message: 'Previous stream warning.',
            ),
        );
        $mapper = new FitActivityImportItemMapper(
            mappers: [],
            warnings: $collector,
        );

        iterator_to_array($mapper->mapStream([]), false);

        self::assertSame([], $mapper->warnings());
    }

    public function testReportsTimerMismatchAndResolutionBoundaryAdjustment(): void
    {
        $sessionFinishedAt = $this->instant(
            '2026-08-05T10:00:30.545000Z',
        );
        $observationAt = $this->instant(
            '2026-08-05T10:00:31.000000Z',
        );
        $startedAt = $this->instant(
            '2026-08-05T10:00:00.000000Z',
        );
        $sessionTimer = Duration::fromMicroseconds(29_000_000);
        $observationCarrierMessageNumber = 65_535;
        $items = [
            $observationCarrierMessageNumber => [new ObservationItem(
                ActivityObservation::create(
                    $observationAt,
                    new PositionMeasurement(
                        new Coordinate(59.4369, 24.7535),
                    ),
                ),
            )],
            19 => [new LapItem(
                Lap::create(
                    startedAt: $startedAt,
                    finishedAt: $sessionFinishedAt,
                    timerDuration: $sessionTimer,
                ),
            )],
            18 => [new SessionItem(
                ActivitySession::create(
                    startedAt: $startedAt,
                    finishedAt: $sessionFinishedAt,
                    timerDuration: $sessionTimer,
                ),
            )],
            34 => [new ActivitySummaryItem(
                summary: ActivitySummary::create(
                    reportedAt: $observationAt,
                    timerDuration: Duration::fromMicroseconds(
                        30_000_000,
                    ),
                    sessionCount: 1,
                ),
                timelineResolution: TemporalResolution::Second,
            )],
        ];
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($items) implements FitMessageMapper {
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
                        $this->items[$message->globalMessageNumber()],
                    );
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return $this->items[
                        $message->globalMessageNumber()
                    ];
                }
            }],
        );

        iterator_to_array(
            $mapper->mapStream([
                $this->message(
                    $observationCarrierMessageNumber,
                    'synthetic_observation',
                ),
                $this->message(19, 'lap'),
                $this->message(18, 'session'),
                $this->message(34, 'activity'),
            ]),
            false,
        );

        $warnings = $mapper->warnings();

        self::assertSame(
            [
                ActivityImportWarningCode::ActivityTimerMismatch,
                ActivityImportWarningCode::TimestampBoundaryAdjusted,
            ],
            array_map(
                static fn (ActivityImportWarning $warning) => $warning->code,
                $warnings,
            ),
        );
        self::assertSame(
            [
                'activityTimerMicroseconds' => 30_000_000,
                'sessionTimerMicroseconds' => 29_000_000,
            ],
            $warnings[0]->context,
        );
        self::assertSame(
            [
                'boundaryType' => 'observation',
                'sessionFinishedAt' => '2026-08-05T10:00:30.545000+00:00',
                'adjustedFinishedAt' => '2026-08-05T10:00:31.000000+00:00',
                'resolutionMicroseconds' => 1_000_000,
            ],
            $warnings[1]->context,
        );
    }

    public function testReportsSubResolutionFinalLapBoundaryAdjustment(): void
    {
        $startedAt = $this->instant(
            '2019-07-25T20:24:05Z',
        );
        $sessionFinishedAt = $this->instant(
            '2019-07-25T20:51:32.595000Z',
        );
        $lapFinishedAt = $this->instant(
            '2019-07-25T20:51:33.448000Z',
        );
        $timerDuration = Duration::fromMicroseconds(
            1_647_595_000,
        );
        $items = [
            19 => [new LapItem(
                Lap::create(
                    startedAt: $this->instant(
                        '2019-07-25T20:48:23Z',
                    ),
                    finishedAt: $lapFinishedAt,
                    timerDuration: Duration::fromMicroseconds(
                        190_448_000,
                    ),
                    timelineResolution: TemporalResolution::Second,
                ),
            )],
            18 => [new SessionItem(
                ActivitySession::create(
                    startedAt: $startedAt,
                    finishedAt: $sessionFinishedAt,
                    timerDuration: $timerDuration,
                ),
            )],
            34 => [new ActivitySummaryItem(
                summary: ActivitySummary::create(
                    reportedAt: $this->instant(
                        '2019-07-25T20:51:37Z',
                    ),
                    timerDuration: $timerDuration,
                    sessionCount: 1,
                ),
                timelineResolution: TemporalResolution::Second,
            )],
        ];
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($items) implements FitMessageMapper {
                /** @param array<int, list<ActivityImportItem>> $items */
                public function __construct(
                    private readonly array $items,
                ) {
                }

                public function supports(
                    UnifiedDataMessage $message,
                ): bool {
                    return isset(
                        $this->items[$message->globalMessageNumber()],
                    );
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return $this->items[
                        $message->globalMessageNumber()
                    ];
                }
            }],
        );

        iterator_to_array(
            $mapper->mapStream([
                $this->message(19, 'lap'),
                $this->message(18, 'session'),
                $this->message(34, 'activity'),
            ]),
            false,
        );

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::TimestampBoundaryAdjusted,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'boundaryType' => 'lap',
                'sessionFinishedAt' => '2019-07-25T20:51:32.595000+00:00',
                'adjustedFinishedAt' => '2019-07-25T20:51:33.448000+00:00',
                'resolutionMicroseconds' => 1_000_000,
            ],
            $warnings[0]->context,
        );
    }

    public function testReportsSubResolutionLifecycleBoundaryAdjustment(): void
    {
        $startedAt = $this->instant(
            '1990-07-09T07:00:41Z',
        );
        $sessionFinishedAt = $this->instant(
            '1990-07-09T07:57:47.898000Z',
        );
        $eventAt = $this->instant(
            '1990-07-09T07:57:48Z',
        );
        $timerDuration = Duration::fromMicroseconds(
            3_426_898_000,
        );
        $items = [
            21 => [new ActivityLifecycleItem(
                action: ActivityLifecycleAction::Pause,
                occurredAt: $eventAt,
            )],
            18 => [new SessionItem(
                ActivitySession::create(
                    startedAt: $startedAt,
                    finishedAt: $sessionFinishedAt,
                    timerDuration: $timerDuration,
                ),
            )],
            34 => [new ActivitySummaryItem(
                summary: ActivitySummary::create(
                    reportedAt: $this->instant(
                        '1990-07-09T07:57:52Z',
                    ),
                    timerDuration: $timerDuration,
                    sessionCount: 1,
                ),
                timelineResolution: TemporalResolution::Second,
            )],
        ];
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($items) implements FitMessageMapper {
                /** @param array<int, list<ActivityImportItem>> $items */
                public function __construct(
                    private readonly array $items,
                ) {
                }

                public function supports(
                    UnifiedDataMessage $message,
                ): bool {
                    return isset(
                        $this->items[$message->globalMessageNumber()],
                    );
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return $this->items[
                        $message->globalMessageNumber()
                    ];
                }
            }],
        );

        iterator_to_array(
            $mapper->mapStream([
                $this->message(21, 'event'),
                $this->message(18, 'session'),
                $this->message(34, 'activity'),
            ]),
            false,
        );

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::TimestampBoundaryAdjusted,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'boundaryType' => 'event',
                'sessionFinishedAt' => '1990-07-09T07:57:47.898000+00:00',
                'adjustedFinishedAt' => '1990-07-09T07:57:48.000000+00:00',
                'resolutionMicroseconds' => 1_000_000,
            ],
            $warnings[0]->context,
        );
    }

    public function testReportsSubResolutionLapOverlapWithoutChangingDurations(): void
    {
        $first = new LapItem(
            Lap::create(
                startedAt: $this->instant(
                    '2019-07-25T20:36:32Z',
                ),
                finishedAt: $this->instant(
                    '2019-07-25T20:48:23.551000Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    711_551_000,
                ),
                timelineResolution: TemporalResolution::Second,
            ),
        );
        $second = new LapItem(
            Lap::create(
                startedAt: $this->instant(
                    '2019-07-25T20:48:23Z',
                ),
                finishedAt: $this->instant(
                    '2019-07-25T20:51:33.448000Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    190_448_000,
                ),
                timelineResolution: TemporalResolution::Second,
            ),
        );
        $items = [19 => [$first, $second]];
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($items) implements FitMessageMapper {
                /** @param array<int, list<ActivityImportItem>> $items */
                public function __construct(
                    private readonly array $items,
                ) {
                }

                public function supports(
                    UnifiedDataMessage $message,
                ): bool {
                    return isset(
                        $this->items[$message->globalMessageNumber()],
                    );
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return $this->items[
                        $message->globalMessageNumber()
                    ];
                }
            }],
        );

        $mapped = iterator_to_array(
            $mapper->mapStream([
                $this->message(19, 'lap'),
            ]),
            false,
        );

        $durations = [];
        foreach ($mapped as $item) {
            self::assertInstanceOf(LapItem::class, $item);
            $durations[] = $item->lap->timerDuration->toMicroseconds();
        }

        self::assertSame(
            [711_551_000, 190_448_000],
            $durations,
        );

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::LapBoundaryResolutionOverlap,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'previousFinishedAt' => '2019-07-25T20:48:23.551000+00:00',
                'startedAt' => '2019-07-25T20:48:23.000000+00:00',
                'overlapMicroseconds' => 551_000,
                'resolutionMicroseconds' => 1_000_000,
            ],
            $warnings[0]->context,
        );
    }

    public function testReportsSubResolutionSessionOverlapWithoutChangingDurations(): void
    {
        $first = new SessionItem(
            ActivitySession::create(
                startedAt: $this->instant(
                    '2019-07-25T20:24:05Z',
                ),
                finishedAt: $this->instant(
                    '2019-07-25T20:48:23.595000Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    1_458_595_000,
                ),
                timelineResolution: TemporalResolution::Second,
            ),
        );
        $second = new SessionItem(
            ActivitySession::create(
                startedAt: $this->instant(
                    '2019-07-25T20:48:23Z',
                ),
                finishedAt: $this->instant(
                    '2019-07-25T20:51:33.448000Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    190_448_000,
                ),
                timelineResolution: TemporalResolution::Second,
            ),
        );
        $items = [18 => [$first, $second]];
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($items) implements FitMessageMapper {
                /** @param array<int, list<ActivityImportItem>> $items */
                public function __construct(
                    private readonly array $items,
                ) {
                }

                public function supports(
                    UnifiedDataMessage $message,
                ): bool {
                    return isset(
                        $this->items[$message->globalMessageNumber()],
                    );
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return $this->items[
                        $message->globalMessageNumber()
                    ];
                }
            }],
        );

        $mapped = iterator_to_array(
            $mapper->mapStream([
                $this->message(18, 'session'),
            ]),
            false,
        );

        $durations = [];
        foreach ($mapped as $item) {
            self::assertInstanceOf(SessionItem::class, $item);
            $durations[] = $item->session->timerDuration->toMicroseconds();
        }

        self::assertSame(
            [1_458_595_000, 190_448_000],
            $durations,
        );

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::SessionBoundaryResolutionOverlap,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'previousFinishedAt' => '2019-07-25T20:48:23.595000+00:00',
                'startedAt' => '2019-07-25T20:48:23.000000+00:00',
                'overlapMicroseconds' => 595_000,
                'resolutionMicroseconds' => 1_000_000,
            ],
            $warnings[0]->context,
        );
    }

    public function testReportsGarminToleratedSessionOverlapBeyondResolution(): void
    {
        $first = new SessionItem(
            ActivitySession::create(
                startedAt: $this->instant(
                    '2014-06-22T11:45:00Z',
                ),
                finishedAt: $this->instant(
                    '2014-06-22T12:13:00.504000Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    1_680_504_000,
                ),
                timelineResolution: TemporalResolution::Second,
            ),
        );
        $second = new SessionItem(
            ActivitySession::create(
                startedAt: $this->instant(
                    '2014-06-22T12:12:59Z',
                ),
                finishedAt: $this->instant(
                    '2014-06-22T12:39:53.807000Z',
                ),
                timerDuration: Duration::fromMicroseconds(
                    1_614_807_000,
                ),
                timelineResolution: TemporalResolution::Second,
            ),
        );

        $warnings = (new FitActivityImportWarningDetector())
            ->sessionBoundaryResolutionOverlaps([$first, $second]);

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::SessionBoundaryResolutionOverlap,
            $warnings[0]->code,
        );
        self::assertSame(
            1_504_000,
            $warnings[0]->context['overlapMicroseconds'],
        );
    }

    public function testReportsExplicitLapLengthReferenceMismatch(): void
    {
        $startedAt = $this->instant('2026-08-05T10:30:00Z');
        $finishedAt = $this->instant('2026-08-05T10:30:25Z');
        $items = [
            101 => [
                new ActivityDetailItem(
                    detail: PoolLength::create(
                        interval: ActivityInterval::create(
                            startedAt: $startedAt,
                            finishedAt: $finishedAt,
                            timerDuration: Duration::between(
                                $startedAt,
                                $finishedAt,
                            ),
                        ),
                        type: PoolLengthType::Active,
                    ),
                    index: 0,
                ),
                new ActivityDetailItem(
                    detail: PoolLength::create(
                        interval: ActivityInterval::create(
                            startedAt: $finishedAt,
                            finishedAt: $this->instant(
                                '2026-08-05T10:30:50Z',
                            ),
                            timerDuration: Duration::fromMicroseconds(
                                25_000_000,
                            ),
                        ),
                        type: PoolLengthType::Active,
                    ),
                    index: 2,
                ),
            ],
            19 => [new LapItem(
                lap: Lap::create(
                    startedAt: $startedAt,
                    finishedAt: $this->instant(
                        '2026-08-05T10:30:50Z',
                    ),
                    timerDuration: Duration::fromMicroseconds(
                        50_000_000,
                    ),
                ),
                index: 0,
                firstLengthIndex: 0,
                lengthCount: 2,
            )],
        ];
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($items) implements FitMessageMapper {
                /** @param array<int, list<ActivityImportItem>> $items */
                public function __construct(
                    private readonly array $items,
                ) {
                }

                public function supports(
                    UnifiedDataMessage $message,
                ): bool {
                    return isset(
                        $this->items[$message->globalMessageNumber()],
                    );
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return $this->items[
                        $message->globalMessageNumber()
                    ];
                }
            }],
        );

        iterator_to_array(
            $mapper->mapStream([
                $this->message(101, 'length'),
                $this->message(19, 'lap'),
            ]),
            false,
        );

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::LapLengthReferenceMismatch,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'firstLengthIndex' => 0,
                'lengthCount' => 2,
                'firstMissingLengthIndex' => 1,
                'missingLengthCount' => 1,
            ],
            $warnings[0]->context,
        );
    }

    public function testReportsLapActiveLengthCountMismatchWhenReferencesAreComplete(): void
    {
        $startedAt = $this->instant('2026-08-05T10:30:00Z');
        $middleAt = $this->instant('2026-08-05T10:30:25Z');
        $finishedAt = $this->instant('2026-08-05T10:30:50Z');
        $items = [
            101 => [
                new ActivityDetailItem(
                    detail: PoolLength::create(
                        interval: ActivityInterval::create(
                            startedAt: $startedAt,
                            finishedAt: $middleAt,
                            timerDuration: Duration::fromMicroseconds(
                                25_000_000,
                            ),
                        ),
                        type: PoolLengthType::Active,
                    ),
                    index: 0,
                ),
                new ActivityDetailItem(
                    detail: PoolLength::create(
                        interval: ActivityInterval::create(
                            startedAt: $middleAt,
                            finishedAt: $finishedAt,
                            timerDuration: Duration::fromMicroseconds(
                                25_000_000,
                            ),
                        ),
                        type: PoolLengthType::Idle,
                    ),
                    index: 1,
                ),
            ],
            19 => [new LapItem(
                lap: Lap::create(
                    startedAt: $startedAt,
                    finishedAt: $finishedAt,
                    timerDuration: Duration::fromMicroseconds(
                        50_000_000,
                    ),
                ),
                index: 0,
                firstLengthIndex: 0,
                lengthCount: 2,
                activeLengthCount: 2,
            )],
        ];
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($items) implements FitMessageMapper {
                /** @param array<int, list<ActivityImportItem>> $items */
                public function __construct(
                    private readonly array $items,
                ) {
                }

                public function supports(
                    UnifiedDataMessage $message,
                ): bool {
                    return isset(
                        $this->items[$message->globalMessageNumber()],
                    );
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return $this->items[
                        $message->globalMessageNumber()
                    ];
                }
            }],
        );

        iterator_to_array(
            $mapper->mapStream([
                $this->message(101, 'length'),
                $this->message(19, 'lap'),
            ]),
            false,
        );

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::LapActiveLengthCountMismatch,
            $warnings[0]->code,
        );
    }

    public function testReportsSessionLengthCountMismatchesWhenReferenceChainIsComplete(): void
    {
        $startedAt = $this->instant('2026-08-05T10:30:00Z');
        $middleAt = $this->instant('2026-08-05T10:30:25Z');
        $finishedAt = $this->instant('2026-08-05T10:30:50Z');
        $items = [
            101 => [
                new ActivityDetailItem(
                    detail: PoolLength::create(
                        interval: ActivityInterval::create(
                            startedAt: $startedAt,
                            finishedAt: $middleAt,
                            timerDuration: Duration::fromMicroseconds(
                                25_000_000,
                            ),
                        ),
                        type: PoolLengthType::Active,
                    ),
                    index: 0,
                ),
                new ActivityDetailItem(
                    detail: PoolLength::create(
                        interval: ActivityInterval::create(
                            startedAt: $middleAt,
                            finishedAt: $finishedAt,
                            timerDuration: Duration::fromMicroseconds(
                                25_000_000,
                            ),
                        ),
                        type: PoolLengthType::Idle,
                    ),
                    index: 1,
                ),
            ],
            19 => [new LapItem(
                lap: Lap::create(
                    startedAt: $startedAt,
                    finishedAt: $finishedAt,
                    timerDuration: Duration::fromMicroseconds(
                        50_000_000,
                    ),
                ),
                index: 0,
                firstLengthIndex: 0,
                lengthCount: 2,
                activeLengthCount: 1,
            )],
            18 => [new SessionItem(
                session: ActivitySession::create(
                    startedAt: $startedAt,
                    finishedAt: $finishedAt,
                    timerDuration: Duration::fromMicroseconds(
                        50_000_000,
                    ),
                ),
                firstLapIndex: 0,
                lapCount: 1,
                lengthCount: 3,
                activeLengthCount: 2,
            )],
        ];
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($items) implements FitMessageMapper {
                /** @param array<int, list<ActivityImportItem>> $items */
                public function __construct(
                    private readonly array $items,
                ) {
                }

                public function supports(
                    UnifiedDataMessage $message,
                ): bool {
                    return isset(
                        $this->items[$message->globalMessageNumber()],
                    );
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return $this->items[
                        $message->globalMessageNumber()
                    ];
                }
            }],
        );

        iterator_to_array(
            $mapper->mapStream([
                $this->message(101, 'length'),
                $this->message(19, 'lap'),
                $this->message(18, 'session'),
            ]),
            false,
        );

        $warnings = $mapper->warnings();

        self::assertCount(2, $warnings);
        self::assertSame(
            ActivityImportWarningCode::SessionLengthCountMismatch,
            $warnings[0]->code,
        );
        self::assertSame(
            ActivityImportWarningCode::SessionActiveLengthCountMismatch,
            $warnings[1]->code,
        );
    }

    public function testReportsSubResolutionSequentialDetailOverlap(): void
    {
        $first = new ActivityDetailItem(
            PoolLength::create(
                interval: ActivityInterval::create(
                    startedAt: $this->instant(
                        '1989-12-31T00:10:57Z',
                    ),
                    finishedAt: $this->instant(
                        '1989-12-31T00:11:20.375000Z',
                    ),
                    timerDuration: Duration::fromMicroseconds(
                        23_375_000,
                    ),
                ),
                type: PoolLengthType::Active,
                timelineResolution: TemporalResolution::Second,
            ),
        );
        $second = new ActivityDetailItem(
            PoolLength::create(
                interval: ActivityInterval::create(
                    startedAt: $this->instant(
                        '1989-12-31T00:11:20Z',
                    ),
                    finishedAt: $this->instant(
                        '1989-12-31T00:12:05.750000Z',
                    ),
                    timerDuration: Duration::fromMicroseconds(
                        45_750_000,
                    ),
                ),
                type: PoolLengthType::Active,
                timelineResolution: TemporalResolution::Second,
            ),
        );
        $items = [101 => [$first, $second]];
        $mapper = new FitActivityImportItemMapper(
            mappers: [new class($items) implements FitMessageMapper {
                /** @param array<int, list<ActivityImportItem>> $items */
                public function __construct(
                    private readonly array $items,
                ) {
                }

                public function supports(
                    UnifiedDataMessage $message,
                ): bool {
                    return isset(
                        $this->items[$message->globalMessageNumber()],
                    );
                }

                public function map(
                    UnifiedDataMessage $message,
                ): array {
                    return $this->items[
                        $message->globalMessageNumber()
                    ];
                }
            }],
        );

        iterator_to_array(
            $mapper->mapStream([
                $this->message(101, 'length'),
            ]),
            false,
        );

        $warnings = $mapper->warnings();

        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::ActivityDetailBoundaryResolutionOverlap,
            $warnings[0]->code,
        );
        self::assertSame(
            [
                'sequenceName' => 'pool_length',
                'previousFinishedAt' => '1989-12-31T00:11:20.375000+00:00',
                'startedAt' => '1989-12-31T00:11:20.000000+00:00',
                'overlapMicroseconds' => 375_000,
                'resolutionMicroseconds' => 1_000_000,
            ],
            $warnings[0]->context,
        );
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

        return (new FitDecoder(
            profiles: new InMemoryFitProfileRegistry($profile),
            types: new InMemoryFitTypeRegistry(),
        ))->decode(
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
