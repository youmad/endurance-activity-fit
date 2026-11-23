<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

use Endurance\Activity\Application\Import\ActivityImportItem;
use Endurance\Activity\Application\Import\LapItem;
use Endurance\Activity\Telemetry\ScalarMeasurement;
use Endurance\Activity\ValueObject\Lap;
use Endurance\Activity\ValueObject\SummaryAdjacencyPolicy;
use Endurance\ActivityFit\Developer\FitDeveloperMeasurementMapper;
use Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Endurance\ActivityFit\Lap\FitLapMeasurementRegistry;
use Endurance\Fit\Unified\UnifiedDataMessage;
use Endurance\Foundation\ValueObject\TemporalResolution;

final readonly class FitLapMessageMapper implements FitMessageMapper
{
    private const int LAP_GLOBAL_MESSAGE_NUMBER = 19;
    private const int MESSAGE_INDEX_MASK = 0x0FFF;

    public function __construct(
        private FitSummaryTimingResolver $timing =
            new FitSummaryTimingResolver(),
        private FitFieldValueReader $values =
            new FitFieldValueReader(),
        private FitLapMeasurementRegistry $measurements =
            new FitLapMeasurementRegistry(),
        private FitMeasurementReadingFactory $readings =
            new FitMeasurementReadingFactory(),
        private FitDeveloperMeasurementMapper $developerMeasurements =
            new FitDeveloperMeasurementMapper(),
    ) {
    }

    public function supports(
        UnifiedDataMessage $message,
    ): bool {
        return self::LAP_GLOBAL_MESSAGE_NUMBER
            === $message->globalMessageNumber();
    }

    /**
     * @return list<ActivityImportItem>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array {
        if (!$this->supports($message)) {
            throw new \LogicException(sprintf('FIT lap mapper does not support global message %d.', $message->globalMessageNumber()));
        }

        $timing = $this->timing->resolve(
            message: $message,
            messageName: 'lap',
        );

        $readings = [];

        foreach ($this->measurements->scalarDefinitions() as $definition) {
            $value = $this->values->bySelectedNames(
                message: $message,
                fieldNames: $definition->fieldNames(),
            );

            if (null === $value) {
                continue;
            }

            if (!is_int($value->value) && !is_float($value->value)) {
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

        return [new LapItem(
            lap: Lap::create(
                startedAt: $timing->startedAt,
                finishedAt: $timing->finishedAt,
                timerDuration: $timing->timerDuration,
                readings: $readings,
                timelineResolution: TemporalResolution::Second,
                adjacencyPolicy: SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds,
            ),
            index: $this->messageIndex($message),
            firstLengthIndex: $this->optionalInteger(
                message: $message,
                fieldName: 'first_length_index',
            ),
            lengthCount: $this->optionalInteger(
                message: $message,
                fieldName: 'num_lengths',
            ),
            activeLengthCount: $this->optionalInteger(
                message: $message,
                fieldName: 'num_active_lengths',
            ),
        )];
    }

    private function optionalInteger(
        UnifiedDataMessage $message,
        string $fieldName,
    ): ?int {
        $value = $this->values->byName(
            message: $message,
            fieldName: $fieldName,
        );

        if (null === $value) {
            return null;
        }

        if (!is_int($value->value)) {
            throw InvalidFitActivityMessage::invalidScalar(fieldName: $fieldName, value: $value->value);
        }

        return $value->value;
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
}
