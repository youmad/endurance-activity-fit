<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Youmad\Endurance\ActivityFit\Conversion\FitDateTimeConverter;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Value\FitTimerState;
use Youmad\Endurance\ActivityFit\Value\SelectedFitFieldValue;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final readonly class FitTimerEventMessageMapper implements ResettableFitMessageMapper
{
    private const int EVENT_GLOBAL_MESSAGE_NUMBER = 21;
    private const int TIMER_EVENT = 0;

    private const int EVENT_TYPE_START = 0;
    private const int EVENT_TYPE_STOP_ALL = 4;

    public function __construct(
        private FitTimerEventState $state =
            new FitTimerEventState(),
        private FitFieldValueReader $values =
            new FitFieldValueReader(),
        private FitDateTimeConverter $dateTimes =
            new FitDateTimeConverter(),
    ) {
    }

    public function supports(
        UnifiedDataMessage $message,
    ): bool {
        return self::EVENT_GLOBAL_MESSAGE_NUMBER
            === $message->globalMessageNumber();
    }

    /**
     * @return list<ActivityImportItem>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array {
        if (!$this->supports($message)) {
            throw new \LogicException(sprintf('FIT timer event mapper does not support global message %d.', $message->globalMessageNumber()));
        }

        $event = $this->values->byName(
            message: $message,
            fieldName: 'event',
        );

        if (null === $event || !$this->isTimerEvent($event)) {
            return [];
        }

        $eventType = $this->requiredValue(
            message: $message,
            fieldName: 'event_type',
        );

        $occurredAt = $this->dateTimes->convert(
            $this->requiredValue(
                message: $message,
                fieldName: 'timestamp',
            ),
        );

        return match (
            $this->eventType($eventType)
        ) {
            ActivityLifecycleAction::Start => $this->start(
                message: $message,
                occurredAt: $occurredAt,
            ),
            ActivityLifecycleAction::Pause => $this->pause(
                message: $message,
                occurredAt: $occurredAt,
            ),
            ActivityLifecycleAction::Finish => $this->finish(
                message: $message,
                occurredAt: $occurredAt,
            ),
            null => [],
            ActivityLifecycleAction::Resume => throw new \LogicException('Resume is resolved from timer state, not directly from a FIT event type.'),
        };
    }

    public function reset(): void
    {
        $this->state->reset();
    }

    private function requiredValue(
        UnifiedDataMessage $message,
        string $fieldName,
    ): SelectedFitFieldValue {
        $value = $this->values->byName(
            message: $message,
            fieldName: $fieldName,
        );

        if (null === $value) {
            throw InvalidFitActivityMessage::missingRequiredField(message: $message, messageName: 'event', fieldName: $fieldName);
        }

        return $value;
    }

    private function isTimerEvent(
        SelectedFitFieldValue $event,
    ): bool {
        return 'timer' === $event->symbolicName()
            || self::TIMER_EVENT === $event->value;
    }

    private function eventType(
        SelectedFitFieldValue $eventType,
    ): ?ActivityLifecycleAction {
        return match (
            $eventType->symbolicName()
        ) {
            'start' => ActivityLifecycleAction::Start,
            'stop_all' => ActivityLifecycleAction::Finish,
            null => $this->numericEventType(
                $eventType->value,
            ),
            default => null,
        };
    }

    private function numericEventType(
        int|float|string $value,
    ): ?ActivityLifecycleAction {
        if (!is_int($value)) {
            return null;
        }

        return match ($value) {
            self::EVENT_TYPE_START => ActivityLifecycleAction::Start,
            self::EVENT_TYPE_STOP_ALL => ActivityLifecycleAction::Finish,
            default => null,
        };
    }

    /**
     * @return list<ActivityImportItem>
     */
    private function start(
        UnifiedDataMessage $message,
        Instant $occurredAt,
    ): array {
        return match ($this->state->state()) {
            FitTimerState::AwaitingStart => $this->transition(
                message: $message,
                occurredAt: $occurredAt,
                nextState: FitTimerState::Running,
                action: ActivityLifecycleAction::Start,
            ),
            FitTimerState::Paused => $this->transition(
                message: $message,
                occurredAt: $occurredAt,
                nextState: FitTimerState::Running,
                action: ActivityLifecycleAction::Resume,
            ),
            FitTimerState::Running => $this->duplicate(
                message: $message,
                occurredAt: $occurredAt,
                state: FitTimerState::Running,
            ),
        };
    }

    /**
     * @return list<ActivityImportItem>
     */
    private function pause(
        UnifiedDataMessage $message,
        Instant $occurredAt,
    ): array {
        return match ($this->state->state()) {
            FitTimerState::AwaitingStart,
            FitTimerState::Running => $this->transition(
                message: $message,
                occurredAt: $occurredAt,
                nextState: FitTimerState::Paused,
                action: ActivityLifecycleAction::Pause,
            ),
            FitTimerState::Paused => $this->duplicate(
                message: $message,
                occurredAt: $occurredAt,
                state: FitTimerState::Paused,
            ),
        };
    }

    /**
     * @return list<ActivityImportItem>
     */
    private function finish(
        UnifiedDataMessage $message,
        Instant $occurredAt,
    ): array {
        $items = $this->pause(
            message: $message,
            occurredAt: $occurredAt,
        );

        // FIT Profile 21.213.0 defines timer as Group 0 with
        // start / stop_all semantics. A later start resumes the timer, so
        // stop_all remains a possible terminal boundary rather than making
        // the state irreversible.
        $items[] = new ActivityLifecycleItem(
            action: ActivityLifecycleAction::Finish,
            occurredAt: $occurredAt,
        );

        return $items;
    }

    /**
     * @return list<ActivityImportItem>
     */
    private function transition(
        UnifiedDataMessage $message,
        Instant $occurredAt,
        FitTimerState $nextState,
        ActivityLifecycleAction $action,
    ): array {
        $this->state->moveTo(
            state: $nextState,
            occurredAt: $occurredAt,
            message: $message,
        );

        return [new ActivityLifecycleItem(
            action: $action,
            occurredAt: $occurredAt,
        )];
    }

    /**
     * @return list<ActivityImportItem>
     */
    private function duplicate(
        UnifiedDataMessage $message,
        Instant $occurredAt,
        FitTimerState $state,
    ): array {
        $this->state->moveTo(
            state: $state,
            occurredAt: $occurredAt,
            message: $message,
        );

        return [];
    }
}
