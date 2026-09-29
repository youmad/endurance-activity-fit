<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Import;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportGenerationClaim;
use Youmad\Endurance\Activity\Application\Import\ActivityImportItemDispatcher;
use Youmad\Endurance\Activity\Application\Import\ActivityImportOutcome;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\Handler\ActivityDetailItemHandler;
use Youmad\Endurance\Activity\Application\Import\Handler\ActivityLifecycleItemHandler;
use Youmad\Endurance\Activity\Application\Import\Handler\ActivitySummaryItemHandler;
use Youmad\Endurance\Activity\Application\Import\Handler\DeviceItemHandler;
use Youmad\Endurance\Activity\Application\Import\Handler\DeviceStatusItemHandler;
use Youmad\Endurance\Activity\Application\Import\Handler\LapItemHandler;
use Youmad\Endurance\Activity\Application\Import\Handler\ObservationItemHandler;
use Youmad\Endurance\Activity\Application\Import\Handler\SessionItemHandler;
use Youmad\Endurance\Activity\Application\Port\ActivityImportGenerationRepository;
use Youmad\Endurance\Activity\Application\Port\ActivityRepository;
use Youmad\Endurance\Activity\Application\Port\ActivityTransaction;
use Youmad\Endurance\Activity\Application\Port\StagedActivityDetailWriter;
use Youmad\Endurance\Activity\Application\Port\StagedActivityDeviceWriter;
use Youmad\Endurance\Activity\Application\Port\StagedActivityObservationWriter;
use Youmad\Endurance\Activity\Application\Port\StagedActivitySessionWriter;
use Youmad\Endurance\Activity\Application\Port\StagedDeviceStatusObservationWriter;
use Youmad\Endurance\Activity\Application\Port\StagedLapWriter;
use Youmad\Endurance\Activity\Application\UseCase\ActivityImportGenerationCoordinator;
use Youmad\Endurance\Activity\Application\UseCase\ApplyActivityLifecycleEvent;
use Youmad\Endurance\Activity\Application\UseCase\ApplyActivitySummary;
use Youmad\Endurance\Activity\Application\UseCase\ImportActivityStream;
use Youmad\Endurance\Activity\Entity\Activity;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Import\FitActivityImportFailed;
use Youmad\Endurance\ActivityFit\Import\ImportFitActivity;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Tests\Fixture\TestFitProfile;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Exception\InvalidFitFile;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class ImportFitActivityTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testImportsCompleteFitStreamAndReturnsReport(): void
    {
        $recordedAt = new \DateTimeImmutable(
            '2026-01-15T10:30:01Z',
        );
        $activity = Activity::start(
            Instant::fromDateTimeImmutable(
                new \DateTimeImmutable('2026-01-15T10:30:00Z'),
            ),
        );
        $generationId = ActivityImportGenerationId::generate();
        $reportedWarnings = null;
        $preparedStartedAt = null;
        $progressPulses = 0;

        $activities = $this->createMock(ActivityRepository::class);
        $activities
            ->expects(self::once())
            ->method('get')
            ->willReturn($activity);
        $activities
            ->expects(self::once())
            ->method('save')
            ->with($activity);

        $observation = null;
        $observations = $this->createMock(
            StagedActivityObservationWriter::class,
        );
        $observations
            ->expects(self::once())
            ->method('append')
            ->willReturnCallback(
                static function (
                    ActivityImportGenerationId $actualGenerationId,
                    ActivityId $activityId,
                    ActivityObservation $value,
                ) use (
                    $generationId,
                    $activity,
                    &$observation,
                ): void {
                    self::assertTrue(
                        $generationId->equals($actualGenerationId),
                    );
                    self::assertTrue($activity->id->equals($activityId));
                    $observation = $value;
                },
            );

        $repository = $this->generationRepository(
            generationId: $generationId,
            completed: false,
        );
        $repository
            ->expects(self::once())
            ->method('activate')
            ->with($generationId);
        $repository->expects(self::never())->method('fail');

        $bytes = $this->fitFile(
            recordedAt: $recordedAt,
            heartRate: 151,
        );
        $input = $this->input($bytes);

        $result = $this->fitImporter(
            activities: $activities,
            observations: $observations,
            generations: $repository,
        )->import(
            activityId: $activity->id,
            idempotencyKey: ActivityImportIdempotencyKey::fromString(
                'fit:complete:1',
            ),
            input: $input,
            onPrepared: static function (Instant $startedAt) use (
                &$preparedStartedAt,
            ): void {
                $preparedStartedAt = $startedAt;
            },
            onImported: static function (array $warnings) use (
                &$reportedWarnings,
            ): void {
                $reportedWarnings = $warnings;
            },
            onProgress: static function () use (&$progressPulses): void {
                ++$progressPulses;
            },
        );

        self::assertTrue($result->isImported());
        self::assertInstanceOf(Instant::class, $preparedStartedAt);
        self::assertTrue(
            $preparedStartedAt->equals(
                Instant::fromDateTimeImmutable($recordedAt),
            ),
        );
        self::assertIsArray($reportedWarnings);
        self::assertSame([], $reportedWarnings);
        self::assertGreaterThan(0, $progressPulses);
        self::assertSame(
            ActivityImportOutcome::Imported,
            $result->activityImport->outcome,
        );
        self::assertNotNull($result->report);
        self::assertSame(0x20, $result->report->protocolVersion);
        self::assertSame(21_208, $result->report->profileVersion);
        self::assertSame(4, $result->report->decodedDataMessages);
        self::assertSame(1, $result->report->items->observations);
        self::assertSame(1, $result->report->items->laps);
        self::assertSame(1, $result->report->items->sessions);
        self::assertSame(strlen($bytes), $result->report->finalBytePosition);
        self::assertSame(
            FitCrc16::calculate(substr($bytes, 0, -2)),
            $result->report->fileCrc,
        );

        self::assertInstanceOf(ActivityObservation::class, $observation);
        self::assertTrue(
            $observation->timestamp->equals(
                Instant::fromDateTimeImmutable($recordedAt),
            ),
        );
        $measurement = $observation->readings()[0]->measurement;
        self::assertInstanceOf(ScalarMeasurement::class, $measurement);
        self::assertSame(151, $measurement->value);
    }

    public function testPreparedImportSkipsObservationBeforeExplicitTimerStart(): void
    {
        $startedAtDateTime = new \DateTimeImmutable(
            '2026-01-15T10:30:00Z',
        );
        // Garmin permits the first Record one second before the first Session.
        $preStartDateTime = $startedAtDateTime->modify('-1 second');
        $startedAt = Instant::fromDateTimeImmutable($startedAtDateTime);
        $activity = Activity::start($startedAt);
        $generationId = ActivityImportGenerationId::generate();
        $reportedWarnings = null;
        $preparedStartedAt = null;

        $activities = $this->createMock(ActivityRepository::class);
        $activities
            ->expects(self::once())
            ->method('get')
            ->willReturn($activity);
        $activities
            ->expects(self::once())
            ->method('save')
            ->with($activity);

        $observedAt = null;
        $observations = $this->createMock(
            StagedActivityObservationWriter::class,
        );
        $observations
            ->expects(self::once())
            ->method('append')
            ->willReturnCallback(
                static function (
                    ActivityImportGenerationId $actualGenerationId,
                    ActivityId $activityId,
                    ActivityObservation $observation,
                ) use (
                    $generationId,
                    $activity,
                    &$observedAt,
                ): void {
                    self::assertTrue(
                        $generationId->equals($actualGenerationId),
                    );
                    self::assertTrue($activity->id->equals($activityId));
                    $observedAt = $observation->timestamp;
                },
            );

        $repository = $this->generationRepository(
            generationId: $generationId,
            completed: false,
        );
        $repository
            ->expects(self::once())
            ->method('activate')
            ->with($generationId);
        $repository->expects(self::never())->method('fail');

        $result = $this->fitImporter(
            activities: $activities,
            observations: $observations,
            generations: $repository,
        )->import(
            activityId: $activity->id,
            idempotencyKey: ActivityImportIdempotencyKey::fromString(
                'fit:pre-start-observation',
            ),
            input: $this->input(
                $this->fitFileWithPreStartObservation(
                    preStartAt: $preStartDateTime,
                    startedAt: $startedAtDateTime,
                ),
            ),
            onPrepared: static function (Instant $resolved) use (
                &$preparedStartedAt,
            ): void {
                $preparedStartedAt = $resolved;
            },
            onImported: static function (array $warnings) use (
                &$reportedWarnings,
            ): void {
                $reportedWarnings = $warnings;
            },
        );

        self::assertTrue($result->isImported());
        self::assertInstanceOf(Instant::class, $preparedStartedAt);
        self::assertTrue($startedAt->equals($preparedStartedAt));
        self::assertInstanceOf(Instant::class, $observedAt);
        self::assertTrue($startedAt->equals($observedAt));
        self::assertIsArray($reportedWarnings);
        self::assertCount(1, $reportedWarnings);
        self::assertSame(
            [
                ActivityImportWarningCode::PreStartObservationsSkipped,
            ],
            array_map(
                static fn ($warning): ActivityImportWarningCode => $warning->code,
                $reportedWarnings,
            ),
        );
        self::assertSame(
            1_000_000,
            $reportedWarnings[0]->context['maximumLeadMicroseconds'],
        );
        self::assertNotNull($result->report);
        self::assertSame($reportedWarnings, $result->report->warnings);
    }

    public function testCompletedDuplicateDecodesFitOnlyOnce(): void
    {
        $activityId = ActivityId::generate();
        $generationId = ActivityImportGenerationId::generate();
        $completionCalled = false;
        $preparedCalled = false;
        $activities = $this->createMock(ActivityRepository::class);
        $activities->expects(self::never())->method('get');
        $activities->expects(self::never())->method('save');

        $repository = $this->generationRepository(
            generationId: $generationId,
            completed: true,
        );
        $repository->expects(self::never())->method('activate');
        $repository->expects(self::never())->method('fail');

        $bytes = $this->fitFile(
            recordedAt: new \DateTimeImmutable(
                '2026-01-15T10:30:01Z',
            ),
            heartRate: 151,
        );
        $input = $this->input($bytes);

        $result = $this->fitImporter(
            activities: $activities,
            observations: $this->createStub(
                StagedActivityObservationWriter::class,
            ),
            generations: $repository,
        )->import(
            activityId: $activityId,
            idempotencyKey: ActivityImportIdempotencyKey::fromString(
                'fit:already-complete',
            ),
            input: $input,
            onPrepared: static function (Instant $startedAt) use (
                &$preparedCalled,
            ): void {
                $preparedCalled = true;
            },
            onImported: static function (array $warnings) use (
                &$completionCalled,
            ): void {
                $completionCalled = true;
            },
        );

        self::assertFalse($result->isImported());
        self::assertTrue($preparedCalled);
        self::assertFalse($completionCalled);
        self::assertNull($result->report);
        self::assertSame(strlen($bytes), $input->position());
        self::assertTrue(
            $generationId->equals(
                $result->activityImport->generationId,
            ),
        );
    }

    public function testPreparedImportWrapsCrcFailureWithActivityContext(): void
    {
        $activityId = ActivityId::generate();
        $preparedCalled = false;
        $bytes = $this->fitFile(
            recordedAt: new \DateTimeImmutable(
                '2026-01-15T10:30:01Z',
            ),
            heartRate: 151,
        );
        $bytes = substr($bytes, 0, -2)."\x00\x00";

        try {
            $this->fitImporter(
                activities: $this->createStub(ActivityRepository::class),
                observations: $this->createStub(
                    StagedActivityObservationWriter::class,
                ),
                generations: $this->createStub(
                    ActivityImportGenerationRepository::class,
                ),
            )->import(
                activityId: $activityId,
                idempotencyKey: ActivityImportIdempotencyKey::fromString(
                    'fit:prepared-broken-crc',
                ),
                input: $this->input($bytes),
                onPrepared: static function () use (
                    &$preparedCalled,
                ): void {
                    $preparedCalled = true;
                },
            );

            self::fail('Expected prepared FIT CRC failure.');
        } catch (FitActivityImportFailed $exception) {
            self::assertFalse($preparedCalled);
            self::assertTrue($activityId->equals($exception->activityId));
            self::assertSame(4, $exception->decodedDataMessages);
            // CRC fails before the mapper replays its deferred items.
            self::assertSame(0, $exception->mappedItems);
            self::assertSame(strlen($bytes), $exception->bytePosition);
            self::assertStringContainsString(
                $activityId->toString(),
                $exception->getMessage(),
            );
            self::assertInstanceOf(
                InvalidFitFile::class,
                $exception->getPrevious(),
            );
        }
    }

    public function testWrapsCrcFailureAfterGenerationClaim(): void
    {
        $recordedAt = new \DateTimeImmutable(
            '2026-01-15T10:30:01Z',
        );
        $activity = Activity::start(
            Instant::fromDateTimeImmutable(
                new \DateTimeImmutable('2026-01-15T10:30:00Z'),
            ),
        );
        $generationId = ActivityImportGenerationId::generate();
        $activities = $this->createMock(ActivityRepository::class);
        $activities
            ->expects(self::once())
            ->method('get')
            ->with(self::identicalTo($activity->id))
            ->willReturn($activity);
        $activities->expects(self::never())->method('save');

        $repository = $this->createMock(
            ActivityImportGenerationRepository::class,
        );
        $repository
            ->expects(self::once())
            ->method('claim')
            ->with(
                self::identicalTo($activity->id),
                self::equalTo(
                    ActivityImportIdempotencyKey::fromString(
                        'fit:broken-crc',
                    ),
                ),
            )
            ->willReturn(
                ActivityImportGenerationClaim::acquired($generationId),
            );
        $repository->expects(self::never())->method('activate');
        $repository
            ->expects(self::once())
            ->method('fail')
            ->with(self::identicalTo($generationId));

        $bytes = $this->fitFile(
            recordedAt: $recordedAt,
            heartRate: 151,
        );
        $bytes = substr($bytes, 0, -2)."\x00\x00";

        try {
            $this->fitImporter(
                activities: $activities,
                observations: $this->createStub(
                    StagedActivityObservationWriter::class,
                ),
                generations: $repository,
            )->import(
                activityId: $activity->id,
                idempotencyKey: ActivityImportIdempotencyKey::fromString(
                    'fit:broken-crc',
                ),
                input: $this->input($bytes),
            );

            self::fail('Expected FIT CRC failure.');
        } catch (FitActivityImportFailed $exception) {
            self::assertSame(4, $exception->decodedDataMessages);
            // The generation is claimed, but no deferred item is emitted.
            self::assertSame(0, $exception->mappedItems);
            self::assertSame(strlen($bytes), $exception->bytePosition);
            self::assertInstanceOf(
                InvalidFitFile::class,
                $exception->getPrevious(),
            );
        }
    }

    public function testPreparedImportRejectsLargePreStartLeadBeforePreparation(): void
    {
        $startedAt = new \DateTimeImmutable('2026-01-15T10:30:00Z');
        $activities = $this->createMock(ActivityRepository::class);
        $activities->expects(self::never())->method('get');
        $activities->expects(self::never())->method('save');
        $observations = $this->createMock(StagedActivityObservationWriter::class);
        $observations->expects(self::never())->method('append');
        $generations = $this->createMock(ActivityImportGenerationRepository::class);
        $generations->expects(self::never())->method('claim');
        $prepared = false;

        try {
            $this->fitImporter($activities, $observations, $generations)->import(
                activityId: ActivityId::generate(),
                idempotencyKey: ActivityImportIdempotencyKey::fromString('fit:invalid-pre-start'),
                input: $this->input($this->fitFileWithPreStartObservation(
                    preStartAt: $startedAt->modify('-196 seconds'),
                    startedAt: $startedAt,
                )),
                onPrepared: static function (Instant $resolved) use (&$prepared): void {
                    $prepared = true;
                },
            );
            self::fail('Pre-start filtering must not hide a Garmin REQUIRED failure.');
        } catch (FitActivityImportFailed $failure) {
            self::assertFalse($prepared);
            self::assertSame(0, $failure->mappedItems);
            self::assertInstanceOf(InvalidFitActivityMessage::class, $failure->getPrevious());
            self::assertStringContainsString('Record Message Timestamps Fall Within Session Message Times', $failure->getMessage());
            self::assertStringContainsString('by 196 seconds', $failure->getMessage());
        }
    }

    private function fitImporter(
        ActivityRepository $activities,
        StagedActivityObservationWriter $observations,
        ActivityImportGenerationRepository $generations,
    ): ImportFitActivity {
        $transaction = $this->transaction();

        $activityImporter = new ImportActivityStream(
            activities: $activities,
            generations: new ActivityImportGenerationCoordinator(
                generations: $generations,
                transaction: $transaction,
            ),
            dispatcher: new ActivityImportItemDispatcher(
                new ActivityLifecycleItemHandler(
                    new ApplyActivityLifecycleEvent(
                        activities: $activities,
                        transaction: $transaction,
                    ),
                ),
                new ActivitySummaryItemHandler(
                    new ApplyActivitySummary(
                        activities: $activities,
                        transaction: $transaction,
                    ),
                ),
                new ObservationItemHandler($observations),
                new LapItemHandler(
                    $this->createStub(StagedLapWriter::class),
                ),
                new ActivityDetailItemHandler(
                    $this->createStub(
                        StagedActivityDetailWriter::class,
                    ),
                ),
                new SessionItemHandler(
                    $this->createStub(
                        StagedActivitySessionWriter::class,
                    ),
                ),
                new DeviceItemHandler(
                    $this->createStub(
                        StagedActivityDeviceWriter::class,
                    ),
                ),
                new DeviceStatusItemHandler(
                    $this->createStub(
                        StagedDeviceStatusObservationWriter::class,
                    ),
                ),
            ),
        );

        return new ImportFitActivity(
            decoder: FitDecoder::standard(TestFitProfile::load()),
            mapper: FitActivityImportItemMapper::standard(),
            activities: $activityImporter,
        );
    }

    /** @return ActivityImportGenerationRepository&MockObject */
    private function generationRepository(
        ActivityImportGenerationId $generationId,
        bool $completed,
    ): ActivityImportGenerationRepository {
        $repository = $this->createMock(
            ActivityImportGenerationRepository::class,
        );
        $repository
            ->expects(self::once())
            ->method('claim')
            ->willReturn(
                $completed
                    ? ActivityImportGenerationClaim::alreadyCompleted(
                        $generationId,
                    )
                    : ActivityImportGenerationClaim::acquired(
                        $generationId,
                    ),
            );

        return $repository;
    }

    private function transaction(): ActivityTransaction
    {
        $transaction = $this->createStub(ActivityTransaction::class);
        $transaction
            ->method('run')
            ->willReturnCallback(
                static fn (\Closure $operation): mixed => $operation(),
            );

        return $transaction;
    }

    private function fitFile(
        \DateTimeImmutable $recordedAt,
        int $heartRate,
    ): string {
        $data = $this->dataSection(
            recordedAt: $recordedAt,
            heartRate: $heartRate,
        );

        $headerWithoutCrc = "\x0E"
            ."\x20"
            .pack('v', 21_208)
            .pack('V', strlen($data))
            .'.FIT';

        $header = $headerWithoutCrc
            .pack(
                'v',
                FitCrc16::calculate($headerWithoutCrc),
            );

        $fileWithoutTrailer = $header.$data;

        return $fileWithoutTrailer
            .pack(
                'v',
                FitCrc16::calculate($fileWithoutTrailer),
            );
    }

    private function dataSection(
        \DateTimeImmutable $recordedAt,
        int $heartRate,
    ): string {
        $definition = "\x40"
            ."\x00"
            ."\x00"
            .pack('v', 20)
            ."\x02"
            ."\xFD\x04\x86"
            ."\x03\x01\x02";

        $fitTimestamp = $recordedAt->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;

        self::assertGreaterThanOrEqual(0, $fitTimestamp);
        self::assertGreaterThanOrEqual(0, $heartRate);
        self::assertLessThanOrEqual(254, $heartRate);

        return $definition
            ."\x00"
            .pack('V', $fitTimestamp)
            .chr($heartRate)
            .$this->summaryMessages(
                startedAt: $recordedAt,
                reportedAt: $recordedAt,
                elapsedMilliseconds: 0,
                timerMilliseconds: 0,
                firstLocalMessageNumber: 1,
            );
    }

    private function fitFileWithPreStartObservation(
        \DateTimeImmutable $preStartAt,
        \DateTimeImmutable $startedAt,
    ): string {
        $recordDefinition = "\x40"
            ."\x00"
            ."\x00"
            .pack('v', 20)
            ."\x02"
            ."\xFD\x04\x86"
            ."\x03\x01\x02";
        $eventDefinition = "\x41"
            ."\x00"
            ."\x00"
            .pack('v', 21)
            ."\x04"
            ."\xFD\x04\x86"
            ."\x00\x01\x00"
            ."\x01\x01\x00"
            ."\x04\x01\x02";
        $preStartTimestamp = $preStartAt->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
        $startTimestamp = $startedAt->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;

        $data = $recordDefinition
            ."\x00".pack('V', $preStartTimestamp).chr(53)
            .$eventDefinition
            ."\x01".pack('V', $startTimestamp)."\x00\x00\x00"
            ."\x00".pack('V', $startTimestamp).chr(52)
            .$this->summaryMessages(
                startedAt: $startedAt,
                reportedAt: $startedAt,
                elapsedMilliseconds: 0,
                timerMilliseconds: 0,
                firstLocalMessageNumber: 2,
            );
        $headerWithoutCrc = "\x0E"
            ."\x20"
            .pack('v', 21_208)
            .pack('V', strlen($data))
            .'.FIT';
        $header = $headerWithoutCrc
            .pack('v', FitCrc16::calculate($headerWithoutCrc));
        $fileWithoutTrailer = $header.$data;

        return $fileWithoutTrailer
            .pack('v', FitCrc16::calculate($fileWithoutTrailer));
    }

    private function summaryMessages(
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $reportedAt,
        int $elapsedMilliseconds,
        int $timerMilliseconds,
        int $firstLocalMessageNumber,
    ): string {
        $lapLocal = $firstLocalMessageNumber;
        $sessionLocal = $firstLocalMessageNumber + 1;
        $activityLocal = $firstLocalMessageNumber + 2;
        $lapDefinition = chr(0x40 | $lapLocal)
            ."\x00\x00"
            .pack('v', 19)
            ."\x05"
            ."\xFE\x02\x84"
            ."\xFD\x04\x86"
            ."\x02\x04\x86"
            ."\x07\x04\x86"
            ."\x08\x04\x86";
        $lap = chr($lapLocal)
            .pack('v', 0)
            .pack('V', $this->fitTimestamp($reportedAt))
            .pack('V', $this->fitTimestamp($startedAt))
            .pack('V', $elapsedMilliseconds)
            .pack('V', $timerMilliseconds);
        $sessionDefinition = chr(0x40 | $sessionLocal)
            ."\x00\x00"
            .pack('v', 18)
            ."\x08"
            ."\xFE\x02\x84"
            ."\xFD\x04\x86"
            ."\x02\x04\x86"
            ."\x07\x04\x86"
            ."\x08\x04\x86"
            ."\x05\x01\x00"
            ."\x19\x02\x84"
            ."\x1A\x02\x84";
        $session = chr($sessionLocal)
            .pack('v', 0)
            .pack('V', $this->fitTimestamp($reportedAt))
            .pack('V', $this->fitTimestamp($startedAt))
            .pack('V', $elapsedMilliseconds)
            .pack('V', $timerMilliseconds)
            ."\x00"
            .pack('v', 0)
            .pack('v', 1);
        $activityDefinition = chr(0x40 | $activityLocal)
            ."\x00\x00"
            .pack('v', 34)
            ."\x04"
            ."\xFD\x04\x86"
            ."\x00\x04\x86"
            ."\x01\x02\x84"
            ."\x05\x04\x86";
        $activity = chr($activityLocal)
            .pack('V', $this->fitTimestamp($reportedAt))
            .pack('V', $timerMilliseconds)
            .pack('v', 1)
            .pack('V', $this->fitTimestamp($reportedAt));

        return $lapDefinition
            .$lap
            .$sessionDefinition
            .$session
            .$activityDefinition
            .$activity;
    }

    private function fitTimestamp(\DateTimeImmutable $value): int
    {
        return $value->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }

    private function input(string $bytes): ResourceFitInput
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        return new ResourceFitInput($stream);
    }
}
