<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Telemetry\ActivityObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\PositionMeasurement;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityFit\Conversion\FitCoordinateConverter;
use Youmad\Endurance\ActivityFit\Conversion\FitDateTimeConverter;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperMeasurementMapper;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Record\FitRecordMeasurementRegistry;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

final readonly class FitRecordMessageMapper implements FitMessageMapper
{
    private const int RECORD_GLOBAL_MESSAGE_NUMBER = 20;

    public function __construct(
        private FitFieldValueReader $values =
            new FitFieldValueReader(),
        private FitDateTimeConverter $dateTimes =
            new FitDateTimeConverter(),
        private FitCoordinateConverter $coordinates =
            new FitCoordinateConverter(),
        private FitRecordMeasurementRegistry $measurements =
            new FitRecordMeasurementRegistry(),
        private FitMeasurementReadingFactory $readings =
            new FitMeasurementReadingFactory(),
        private FitDeveloperMeasurementMapper $developerMeasurements =
            new FitDeveloperMeasurementMapper(),
    ) {
    }

    public function supports(
        UnifiedDataMessage $message,
    ): bool {
        return self::RECORD_GLOBAL_MESSAGE_NUMBER
            === $message->globalMessageNumber();
    }

    /**
     * @return list<ActivityImportItem>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array {
        if (!$this->supports($message)) {
            throw new \LogicException(sprintf('FIT record mapper does not support global message %d.', $message->globalMessageNumber()));
        }

        $timestamp = $this->values->byName(
            message: $message,
            fieldName: 'timestamp',
        );

        if (null === $timestamp) {
            return [];
        }

        $observedAt = $this->dateTimes->convert(
            $timestamp,
        );

        $readings = [];

        $this->appendPosition(
            message: $message,
            readings: $readings,
        );

        foreach (
            $this->measurements->scalarDefinitionsForFieldNames(
                $this->values->fieldNames($message),
            ) as $definition
        ) {
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

            $scalarValue = $value->value;
            $origins = [$value->origin()];
            $selectedFieldName = $value->fieldName();
            $additiveFieldName = null === $selectedFieldName
                ? null
                : $definition->fallbackAdditiveFieldName(
                    $selectedFieldName,
                );

            if (null !== $additiveFieldName) {
                $additiveValue = $this->values->byName(
                    message: $message,
                    fieldName: $additiveFieldName,
                );

                if (null !== $additiveValue) {
                    if (
                        !is_int($additiveValue->value)
                        && !is_float($additiveValue->value)
                    ) {
                        throw InvalidFitActivityMessage::invalidScalar(fieldName: $additiveFieldName, value: $additiveValue->value);
                    }

                    $scalarValue += $additiveValue->value;
                    $origins[] = $additiveValue->origin();
                }
            }

            $readings[] = $this->readings->create(
                new ScalarMeasurement(
                    measurementType: $definition->measurementType,
                    value: $scalarValue,
                    unit: $definition->unit,
                ),
                ...$origins,
            );
        }

        $readings = [
            ...$readings,
            ...$this->developerMeasurements->map($message),
        ];

        if ([] === $readings) {
            return [];
        }

        return [new ObservationItem(
            ActivityObservation::fromReadings(
                $observedAt,
                ...$readings,
            ),
        )];
    }

    /**
     * @param list<MeasurementReading> $readings
     */
    private function appendPosition(
        UnifiedDataMessage $message,
        array &$readings,
    ): void {
        $latitude = $this->values->byName(
            message: $message,
            fieldName: 'position_lat',
        );

        $longitude = $this->values->byName(
            message: $message,
            fieldName: 'position_long',
        );

        if (
            null === $latitude
            || null === $longitude
        ) {
            return;
        }

        $readings[] = $this->readings->create(
            new PositionMeasurement(
                $this->coordinates->convert(
                    latitude: $latitude,
                    longitude: $longitude,
                ),
            ),
            $latitude->origin(),
            $longitude->origin(),
        );
    }
}
