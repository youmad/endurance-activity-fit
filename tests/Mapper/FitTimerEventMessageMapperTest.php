<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitTimerEventMessageMapper;
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

final class FitTimerEventMessageMapperTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    private const int EVENT_TIMER = 0;
    private const int EVENT_LAP = 9;

    private const int EVENT_TYPE_START = 0;
    private const int EVENT_TYPE_STOP = 1;
    private const int EVENT_TYPE_MARKER = 3;
    private const int EVENT_TYPE_STOP_ALL = 4;
    private const int EVENT_TYPE_STOP_DISABLE = 8;
    private const int EVENT_TYPE_STOP_DISABLE_ALL = 9;

    public function testMapsCompleteTimerLifecycle(): void
    {
        $items = iterator_to_array(
            FitActivityImportItemMapper::standard()->mapStream(
                [
                    $this->message(
                        timestamp: '2026-01-15T10:30:00Z',
                        eventType: self::EVENT_TYPE_START,
                    ),
                    $this->message(
                        timestamp: '2026-01-15T10:45:00Z',
                        eventType: self::EVENT_TYPE_STOP_ALL,
                    ),
                    $this->message(
                        timestamp: '2026-01-15T10:50:00Z',
                        eventType: self::EVENT_TYPE_START,
                    ),
                    $this->message(
                        timestamp: '2026-01-15T11:00:00Z',
                        eventType: self::EVENT_TYPE_STOP_ALL,
                    ),
                ],
            ),
            false,
        );

        self::assertSame(
            [
                ActivityLifecycleAction::TimerStart,
                ActivityLifecycleAction::Pause,
                ActivityLifecycleAction::Resume,
                ActivityLifecycleAction::Pause,
                ActivityLifecycleAction::Finish,
            ],
            array_map(
                static fn (
                    ActivityLifecycleItem $item,
                ): ActivityLifecycleAction => $item->action,
                $this->lifecycleItems($items),
            ),
        );

        self::assertSame(
            [
                '2026-01-15T10:30:00+00:00',
                '2026-01-15T10:45:00+00:00',
                '2026-01-15T10:50:00+00:00',
                '2026-01-15T11:00:00+00:00',
                '2026-01-15T11:00:00+00:00',
            ],
            array_map(
                static fn (
                    ActivityLifecycleItem $item,
                ): string => $item
                    ->occurredAt
                    ->toDateTimeImmutable()
                    ->format(DATE_ATOM),
                $this->lifecycleItems($items),
            ),
        );
    }

    public function testIgnoresUnsupportedTimerEventTypes(): void
    {
        foreach (
            [
                self::EVENT_TYPE_STOP,
                self::EVENT_TYPE_STOP_DISABLE,
                self::EVENT_TYPE_STOP_DISABLE_ALL,
            ] as $eventType
        ) {
            $mapper = new FitTimerEventMessageMapper();

            self::assertSame(
                [],
                $mapper->map(
                    $this->message(
                        timestamp: '2026-01-15T10:45:00Z',
                        eventType: $eventType,
                    ),
                ),
            );

            self::assertSame(
                ActivityLifecycleAction::TimerStart,
                $this->lifecycleItems($mapper->map(
                    $this->message(
                        timestamp: '2026-01-15T10:46:00Z',
                        eventType: self::EVENT_TYPE_START,
                    ),
                ))[0]->action,
            );
        }
    }

    public function testIgnoresNonTimerEvents(): void
    {
        self::assertSame(
            [],
            (new FitTimerEventMessageMapper())->map(
                $this->message(
                    timestamp: '2026-01-15T10:45:00Z',
                    eventType: self::EVENT_TYPE_MARKER,
                    event: self::EVENT_LAP,
                ),
            ),
        );
    }

    public function testIgnoresEventWithoutUsableEventField(): void
    {
        self::assertSame(
            [],
            (new FitTimerEventMessageMapper())->map(
                $this->message(
                    timestamp: '2026-01-15T10:45:00Z',
                    eventType: self::EVENT_TYPE_START,
                    event: 0xFF,
                ),
            ),
        );
    }

    public function testDuplicateStartEventsAreIdempotent(): void
    {
        $mapper = new FitTimerEventMessageMapper();

        self::assertCount(
            1,
            $mapper->map(
                $this->message(
                    timestamp: '2026-01-15T10:30:00Z',
                    eventType: self::EVENT_TYPE_START,
                ),
            ),
        );

        self::assertSame(
            [],
            $mapper->map(
                $this->message(
                    timestamp: '2026-01-15T10:30:01Z',
                    eventType: self::EVENT_TYPE_START,
                ),
            ),
        );
    }

    public function testStartAfterStopAllResumesTimer(): void
    {
        $mapper = new FitTimerEventMessageMapper();

        $mapper->map(
            $this->message(
                timestamp: '2026-01-15T10:30:00Z',
                eventType: self::EVENT_TYPE_START,
            ),
        );

        self::assertSame(
            [
                ActivityLifecycleAction::Pause,
                ActivityLifecycleAction::Finish,
            ],
            array_map(
                static fn (
                    ActivityLifecycleItem $item,
                ): ActivityLifecycleAction => $item->action,
                $this->lifecycleItems($mapper->map(
                    $this->message(
                        timestamp: '2026-01-15T11:00:00Z',
                        eventType: self::EVENT_TYPE_STOP_ALL,
                    ),
                )),
            ),
        );

        self::assertSame(
            ActivityLifecycleAction::Resume,
            $this->lifecycleItems($mapper->map(
                $this->message(
                    timestamp: '2026-01-15T11:01:00Z',
                    eventType: self::EVENT_TYPE_START,
                ),
            ))[0]->action,
        );
    }

    public function testStreamDiscardsStopAllTerminalCandidateAfterResume(): void
    {
        $items = iterator_to_array(
            FitActivityImportItemMapper::standard()->mapStream(
                [
                    $this->message(
                        timestamp: '2026-01-15T10:30:00Z',
                        eventType: self::EVENT_TYPE_START,
                    ),
                    $this->message(
                        timestamp: '2026-01-15T11:00:00Z',
                        eventType: self::EVENT_TYPE_STOP_ALL,
                    ),
                    $this->message(
                        timestamp: '2026-01-15T11:01:00Z',
                        eventType: self::EVENT_TYPE_START,
                    ),
                ],
            ),
            false,
        );

        self::assertSame(
            [
                ActivityLifecycleAction::TimerStart,
                ActivityLifecycleAction::Pause,
                ActivityLifecycleAction::Resume,
            ],
            array_map(
                static fn (
                    ActivityLifecycleItem $item,
                ): ActivityLifecycleAction => $item->action,
                $this->lifecycleItems($items),
            ),
        );
    }

    public function testStreamKeepsFinalStopAllAsTerminalCandidateWithoutSummary(): void
    {
        $items = iterator_to_array(
            FitActivityImportItemMapper::standard()->mapStream(
                [
                    $this->message(
                        timestamp: '2026-01-15T10:30:00Z',
                        eventType: self::EVENT_TYPE_START,
                    ),
                    $this->message(
                        timestamp: '2026-01-15T11:00:00Z',
                        eventType: self::EVENT_TYPE_STOP_ALL,
                    ),
                ],
            ),
            false,
        );

        self::assertSame(
            [
                ActivityLifecycleAction::TimerStart,
                ActivityLifecycleAction::Pause,
                ActivityLifecycleAction::Finish,
            ],
            array_map(
                static fn (
                    ActivityLifecycleItem $item,
                ): ActivityLifecycleAction => $item->action,
                $this->lifecycleItems($items),
            ),
        );
    }

    public function testRejectsTimerEventsOutOfOrder(): void
    {
        $mapper = new FitTimerEventMessageMapper();

        $mapper->map(
            $this->message(
                timestamp: '2026-01-15T10:45:00Z',
                eventType: self::EVENT_TYPE_STOP_ALL,
            ),
        );

        $this->expectException(
            InvalidFitActivityMessage::class,
        );

        $this->expectExceptionMessage(
            'before previous timer event',
        );

        $mapper->map(
            $this->message(
                timestamp: '2026-01-15T10:44:59Z',
                eventType: self::EVENT_TYPE_START,
            ),
        );
    }

    public function testResetRestoresInitialStartSemantics(): void
    {
        $mapper = new FitTimerEventMessageMapper();

        $mapper->map(
            $this->message(
                timestamp: '2026-01-15T10:30:00Z',
                eventType: self::EVENT_TYPE_START,
            ),
        );

        $mapper->map(
            $this->message(
                timestamp: '2026-01-15T10:45:00Z',
                eventType: self::EVENT_TYPE_STOP_ALL,
            ),
        );

        self::assertSame(
            ActivityLifecycleAction::Resume,
            $this->lifecycleItems($mapper->map(
                $this->message(
                    timestamp: '2026-01-15T10:50:00Z',
                    eventType: self::EVENT_TYPE_START,
                ),
            ))[0]->action,
        );

        $mapper->reset();

        self::assertSame(
            ActivityLifecycleAction::TimerStart,
            $this->lifecycleItems($mapper->map(
                $this->message(
                    timestamp: '2026-01-16T10:30:00Z',
                    eventType: self::EVENT_TYPE_START,
                ),
            ))[0]->action,
        );
    }

    public function testStandardStreamMapperResetsTimerState(): void
    {
        $mapper = FitActivityImportItemMapper::standard();

        $first = iterator_to_array(
            $mapper->mapStream(
                [
                    $this->message(
                        timestamp: '2026-01-15T10:30:00Z',
                        eventType: self::EVENT_TYPE_START,
                    ),
                    $this->message(
                        timestamp: '2026-01-15T10:45:00Z',
                        eventType: self::EVENT_TYPE_STOP_ALL,
                    ),
                ],
            ),
            false,
        );

        self::assertSame(
            [
                ActivityLifecycleAction::TimerStart,
                ActivityLifecycleAction::Pause,
                ActivityLifecycleAction::Finish,
            ],
            array_map(
                static fn (
                    ActivityLifecycleItem $item,
                ): ActivityLifecycleAction => $item->action,
                $this->lifecycleItems($first),
            ),
        );

        $second = iterator_to_array(
            $mapper->mapStream(
                [
                    $this->message(
                        timestamp: '2026-01-16T10:30:00Z',
                        eventType: self::EVENT_TYPE_START,
                    ),
                ],
            ),
            false,
        );

        self::assertSame(
            ActivityLifecycleAction::TimerStart,
            $this->lifecycleItems($second)[0]->action,
        );
    }

    /**
     * @param iterable<ActivityImportItem> $items
     *
     * @return list<ActivityLifecycleItem>
     */
    private function lifecycleItems(iterable $items): array
    {
        $lifecycleItems = [];

        foreach ($items as $item) {
            self::assertInstanceOf(ActivityLifecycleItem::class, $item);
            $lifecycleItems[] = $item;
        }

        return $lifecycleItems;
    }

    private function message(
        string $timestamp,
        int $eventType,
        int $event = self::EVENT_TIMER,
    ): UnifiedDataMessage {
        $fields = [
            253 => [
                'base_type' => 0x86,
                'bytes' => pack(
                    'V',
                    $this->fitTimestamp($timestamp),
                ),
            ],
            0 => [
                'base_type' => 0x00,
                'bytes' => chr($event),
            ],
            1 => [
                'base_type' => 0x00,
                'bytes' => chr($eventType),
            ],
        ];

        $definitions = [];
        $rawFields = [];

        foreach ($fields as $fieldNumber => $field) {
            $definition = StandardFieldDefinition::create(
                fieldNumber: $fieldNumber,
                size: strlen($field['bytes']),
                baseType: FitBaseType::fromDefinitionByte(
                    $field['base_type'],
                ),
            );

            $definitions[] = $definition;

            $rawFields[] = new RawStandardField(
                definition: $definition,
                value: RawFieldValue::fromBytes(
                    $field['bytes'],
                ),
            );
        }

        $definition = MessageDefinition::create(
            localMessageNumber: 0,
            architecture: FitArchitecture::LittleEndian,
            globalMessageNumber: 21,
            standardFields: $definitions,
        );

        return FitDecoder::standard(TestFitProfile::load())->decode(
            RawDataMessage::create(
                sequenceNumber: 1,
                byteOffset: 20,
                recordHeaderByte: 0x00,
                definition: $definition,
                standardFields: $rawFields,
            ),
        );
    }

    private function fitTimestamp(string $value): int
    {
        return (
            new \DateTimeImmutable($value)
        )->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }
}
