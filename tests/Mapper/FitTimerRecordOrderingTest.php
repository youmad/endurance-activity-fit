<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Entity\Activity;
use Youmad\Endurance\Activity\Exception\CannotRecordObservation;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitRecordImportProjection;
use Youmad\Endurance\ActivityFit\Mapper\FitRecordMessageMapper;
use Youmad\Endurance\ActivityFit\Tests\Fixture\TestFitProfile;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\FitBaseType;
use Youmad\Endurance\Fit\Raw\MessageDefinition;
use Youmad\Endurance\Fit\Raw\RawDataMessage;
use Youmad\Endurance\Fit\Raw\RawFieldValue;
use Youmad\Endurance\Fit\Raw\RawStandardField;
use Youmad\Endurance\Fit\Raw\StandardFieldDefinition;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class FitTimerRecordOrderingTest extends TestCase
{
    /** @param list<string> $order */
    #[DataProvider('serializationOrders')]
    public function testMergesTimerEventsWithoutChangingSourceTimes(
        array $order,
        bool $projectRecords,
    ): void {
        $messages = [
            'start' => $this->message(0, 0),
            'pause' => $this->message(2, 4),
            'resume' => $this->message(4, 0),
            'finish' => $this->message(6, 4),
            'r0' => $this->message(0),
            'r2' => $this->message(2),
            'r4' => $this->message(4),
            'r6' => $this->message(6),
        ];
        $source = (static function () use (
            $order,
            $messages,
            $projectRecords,
        ): iterable {
            $records = new FitRecordMessageMapper();

            foreach ($order as $key) {
                $message = $messages[$key];

                yield $projectRecords && 20 === $message->globalMessageNumber()
                    ? new FitRecordImportProjection($records->map($message))
                    : $message;
            }
        })();
        $activity = Activity::start($this->instant(0));
        $timeline = [];

        foreach (FitActivityImportItemMapper::standard()->mapStream($source) as $item) {
            if ($item instanceof ObservationItem) {
                $activity->recordObservation($item->observation);
                $timeline[] = ['record', $item->observation->timestamp
                    ->toDateTimeImmutable()->format('s.u')];
            } elseif ($item instanceof ActivityLifecycleItem) {
                match ($item->action) {
                    ActivityLifecycleAction::Start => $activity->confirmStartedAt($item->occurredAt),
                    ActivityLifecycleAction::Pause => $activity->pause($item->occurredAt),
                    ActivityLifecycleAction::Resume => $activity->resume($item->occurredAt),
                    ActivityLifecycleAction::Finish => $activity->finish($item->occurredAt),
                };
                $timeline[] = [$item->action->value, $item->occurredAt
                    ->toDateTimeImmutable()->format('s.u')];
            }
        }

        self::assertSame([
            ['start', '00.000000'],
            ['record', '00.000000'],
            ['pause', '02.000000'],
            ['record', '02.000000'],
            ['resume', '04.000000'],
            ['record', '04.000000'],
            ['pause', '06.000000'],
            ['record', '06.000000'],
            ['finish', '06.000000'],
        ], $timeline);
        self::assertNotNull($activity->finishedAt);
        self::assertTrue($activity->finishedAt->equals($this->instant(6)));
        self::assertSame(
            2_000_000,
            $activity->accumulatedPausedDuration->toMicroseconds(),
        );
    }

    /** @return iterable<string, array{list<string>, bool}> */
    public static function serializationOrders(): iterable
    {
        $orders = [
            'events first' => ['start', 'pause', 'resume', 'finish', 'r0', 'r2', 'r4', 'r6'],
            'events last' => ['r0', 'r2', 'r4', 'r6', 'start', 'pause', 'resume', 'finish'],
            'interleaved' => ['start', 'r0', 'pause', 'r2', 'resume', 'r4', 'finish', 'r6'],
            'delayed middle events' => ['start', 'r0', 'r2', 'r4', 'pause', 'resume', 'r6', 'finish'],
        ];

        foreach ($orders as $name => $order) {
            yield $name.' unified' => [$order, false];
            yield $name.' projected' => [$order, true];
        }
    }

    public function testDoesNotSortOutOfOrderRecords(): void
    {
        $items = iterator_to_array(
            FitActivityImportItemMapper::standard()->mapStream([
                $this->message(0),
                $this->message(4),
                $this->message(2),
                $this->message(0, 0),
                $this->message(6, 4),
            ]),
            false,
        );
        $activity = Activity::start($this->instant(0));

        $this->expectException(CannotRecordObservation::class);
        $this->expectExceptionMessage(
            'Observation cannot be earlier than the latest activity event.',
        );

        foreach ($items as $item) {
            if ($item instanceof ObservationItem) {
                $activity->recordObservation($item->observation);
            }
        }
    }

    public function testRejectsOutOfOrderTimerEventsAfterRecords(): void
    {
        $this->expectException(InvalidFitActivityMessage::class);

        iterator_to_array(
            FitActivityImportItemMapper::standard()->mapStream([
                $this->message(0),
                $this->message(6),
                $this->message(0, 0),
                $this->message(4, 4),
                $this->message(2, 0),
            ]),
            false,
        );
    }

    public function testRecordsWithoutTimerEventsKeepTheirOrder(): void
    {
        $times = [];

        foreach (
            FitActivityImportItemMapper::standard()->mapStream([
                $this->message(0),
                $this->message(2),
                $this->message(6),
            ]) as $item
        ) {
            if ($item instanceof ObservationItem) {
                $times[] = $item->observation->timestamp
                    ->toDateTimeImmutable()->format('s.u');
            }
        }

        self::assertSame(['00.000000', '02.000000', '06.000000'], $times);
    }

    private function instant(int $second): Instant
    {
        return Instant::fromDateTimeImmutable(new \DateTimeImmutable(
            sprintf('2026-01-15T10:30:%02dZ', $second),
        ));
    }

    private function message(int $second, ?int $eventType = null): UnifiedDataMessage
    {
        $fields = [
            253 => [0x86, pack(
                'V',
                $this->instant($second)->toDateTimeImmutable()->getTimestamp()
                    - 631_065_600,
            )],
        ];

        if (null === $eventType) {
            $fields[3] = [0x02, chr(150)];
        } else {
            $fields[0] = [0x00, chr(0)];
            $fields[1] = [0x00, chr($eventType)];
        }

        $definitions = [];
        $rawFields = [];

        foreach ($fields as $fieldNumber => [$baseType, $bytes]) {
            $definition = StandardFieldDefinition::create(
                fieldNumber: $fieldNumber,
                size: strlen($bytes),
                baseType: FitBaseType::fromDefinitionByte($baseType),
            );
            $definitions[] = $definition;
            $rawFields[] = new RawStandardField(
                definition: $definition,
                value: RawFieldValue::fromBytes($bytes),
            );
        }

        return FitDecoder::standard(TestFitProfile::load())->decode(RawDataMessage::create(
            sequenceNumber: 1,
            byteOffset: 20,
            recordHeaderByte: 0x00,
            definition: MessageDefinition::create(
                localMessageNumber: 0,
                architecture: FitArchitecture::LittleEndian,
                globalMessageNumber: null === $eventType ? 20 : 21,
                standardFields: $definitions,
            ),
            standardFields: $rawFields,
        ));
    }
}
