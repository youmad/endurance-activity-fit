<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Detail\ActivityInterval;
use Youmad\Endurance\Activity\Detail\Pool\PoolLength;
use Youmad\Endurance\Activity\Detail\Pool\PoolLengthType;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperMeasurementMapper;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\PoolSwimming\FitPoolLengthMeasurementRegistry;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final readonly class FitPoolLengthMessageMapper implements FitMessageMapper
{
    private const int LENGTH_GLOBAL_MESSAGE_NUMBER = 101;
    private const int MESSAGE_INDEX_MASK = 0x0FFF;

    public function __construct(
        private FitSummaryTimingResolver $timing =
            new FitSummaryTimingResolver(),
        private FitFieldValueReader $values =
            new FitFieldValueReader(),
        private FitPoolLengthMeasurementRegistry $measurements =
            new FitPoolLengthMeasurementRegistry(),
        private FitMeasurementReadingFactory $readings =
            new FitMeasurementReadingFactory(),
        private FitDeveloperMeasurementMapper $developerMeasurements =
            new FitDeveloperMeasurementMapper(),
    ) {
    }

    public function supports(
        UnifiedDataMessage $message,
    ): bool {
        return self::LENGTH_GLOBAL_MESSAGE_NUMBER
            === $message->globalMessageNumber();
    }

    /**
     * @return list<ActivityImportItem>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array {
        if (!$this->supports($message)) {
            throw new \LogicException(sprintf('FIT pool length mapper does not support global message %d.', $message->globalMessageNumber()));
        }

        // Length.event and Length.event_type are optional Profile metadata.
        // Unlike Session, Profile 21.213.0 does not constrain them to a
        // canonical pair, so they do not participate in domain mapping.

        $type = $this->requiredSymbolic(
            message: $message,
            fieldName: 'length_type',
        );

        $lengthType = match ($type) {
            'active' => PoolLengthType::Active,
            'idle' => PoolLengthType::Idle,
            default => throw InvalidFitActivityMessage::unexpectedFieldValue(message: $message, messageName: 'length', fieldName: 'length_type', expected: 'active or idle', actual: $type),
        };

        $timing = $this->timing->resolve(
            message: $message,
            messageName: 'length',
        );

        $readings = [];

        foreach ($this->measurements->scalarDefinitions() as $definition) {
            if (
                $definition->activeOnly
                && PoolLengthType::Idle === $lengthType
            ) {
                continue;
            }

            $value = $this->values->byNames(
                message: $message,
                fieldNames: $definition->fieldNames(),
            );

            if (null === $value) {
                continue;
            }

            if (
                !is_int($value->value)
                && !is_float($value->value)
            ) {
                throw InvalidFitActivityMessage::invalidScalar(fieldName: $value->fieldName() ?? $definition->measurementType->toString(), value: $value->value);
            }

            $readings[] = $this->readings->create(
                new ScalarMeasurement(
                    measurementType: $definition->measurementType,
                    value: $value->value,
                    unit: $definition->unit,
                ),
                $value->origin(),
            );
        }

        $readings = [
            ...$readings,
            ...$this->developerMeasurements->map($message),
        ];

        $stroke = PoolLengthType::Active === $lengthType
            ? $this->values
                ->byName(
                    message: $message,
                    fieldName: 'swim_stroke',
                )
                ?->symbolicName()
            : null;

        return [new ActivityDetailItem(
            detail: PoolLength::create(
                interval: ActivityInterval::create(
                    startedAt: $timing->startedAt,
                    finishedAt: $timing->finishedAt,
                    timerDuration: $timing->timerDuration,
                ),
                type: $lengthType,
                stroke: $stroke,
                readings: $readings,
                timelineResolution: TemporalResolution::Second,
            ),
            index: $this->messageIndex($message),
        )];
    }

    private function messageIndex(
        UnifiedDataMessage $message,
    ): ?int {
        $value = $this->values->byName(
            message: $message,
            fieldName: 'message_index',
        );

        if (null === $value) {
            return null;
        }

        if (!is_int($value->value)) {
            throw InvalidFitActivityMessage::invalidScalar(fieldName: 'message_index', value: $value->value);
        }

        return $value->value & self::MESSAGE_INDEX_MASK;
    }

    private function requiredSymbolic(
        UnifiedDataMessage $message,
        string $fieldName,
    ): string {
        $value = $this->values->byName(
            message: $message,
            fieldName: $fieldName,
        );

        $symbolic = $value?->symbolicName();

        if (null === $value || null === $symbolic) {
            throw InvalidFitActivityMessage::missingRequiredField(message: $message, messageName: 'length', fieldName: $fieldName);
        }

        return $symbolic;
    }
}
