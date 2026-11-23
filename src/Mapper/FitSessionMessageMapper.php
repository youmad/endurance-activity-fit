<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Mapper;

use Endurance\Activity\Application\Import\ActivityImportItem;
use Endurance\Activity\Application\Import\SessionItem;
use Endurance\Activity\Session\ActivitySession;
use Endurance\Activity\Telemetry\ScalarMeasurement;
use Endurance\Activity\ValueObject\SummaryAdjacencyPolicy;
use Endurance\ActivityFit\Conversion\FitCoordinateConverter;
use Endurance\ActivityFit\Developer\FitDeveloperMeasurementMapper;
use Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Endurance\ActivityFit\Session\FitSessionMeasurementRegistry;
use Endurance\Fit\Unified\UnifiedDataMessage;
use Endurance\Foundation\ValueObject\Coordinate;
use Endurance\Foundation\ValueObject\TemporalResolution;

final readonly class FitSessionMessageMapper implements FitMessageMapper
{
    private const int SESSION_GLOBAL_MESSAGE_NUMBER = 18;

    public function __construct(
        private FitSummaryTimingResolver $timing =
            new FitSummaryTimingResolver(),
        private FitFieldValueReader $values =
            new FitFieldValueReader(),
        private FitCoordinateConverter $coordinates =
            new FitCoordinateConverter(),
        private FitSessionMeasurementRegistry $measurements =
            new FitSessionMeasurementRegistry(),
        private FitMeasurementReadingFactory $readings =
            new FitMeasurementReadingFactory(),
        private FitDeveloperMeasurementMapper $developerMeasurements =
            new FitDeveloperMeasurementMapper(),
    ) {
    }

    public function supports(
        UnifiedDataMessage $message,
    ): bool {
        return self::SESSION_GLOBAL_MESSAGE_NUMBER
            === $message->globalMessageNumber();
    }

    /**
     * @return list<ActivityImportItem>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array {
        if (!$this->supports($message)) {
            throw new \LogicException(sprintf('FIT session mapper does not support global message %d.', $message->globalMessageNumber()));
        }

        $timing = $this->timing->resolve(
            message: $message,
            messageName: 'session',
        );

        $readings = [];

        foreach (
            $this->measurements->scalarDefinitions() as $definition
        ) {
            $value = $this->values->bySelectedNames(
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

        $sport = $this->symbolic(
            fieldName: 'sport',
            message: $message,
        );

        return [new SessionItem(
            session: ActivitySession::create(
                startedAt: $timing->startedAt,
                finishedAt: $timing->finishedAt,
                timerDuration: $timing->timerDuration,
                sport: $sport,
                subSport: null === $sport
                    ? null
                    : $this->symbolic(
                        fieldName: 'sub_sport',
                        message: $message,
                    ),
                startPosition: $this->position(
                    message: $message,
                    latitudeField: 'start_position_lat',
                    longitudeField: 'start_position_long',
                ),
                endPosition: $this->position(
                    message: $message,
                    latitudeField: 'end_position_lat',
                    longitudeField: 'end_position_long',
                ),
                readings: $readings,
                timelineResolution: TemporalResolution::Second,
                adjacencyPolicy: SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds,
            ),
            firstLapIndex: $this->optionalInteger(
                message: $message,
                fieldName: 'first_lap_index',
            ),
            lapCount: $this->optionalInteger(
                message: $message,
                fieldName: 'num_laps',
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

    private function symbolic(
        string $fieldName,
        UnifiedDataMessage $message,
    ): ?string {
        $value = $this->values->byName(
            message: $message,
            fieldName: $fieldName,
        );

        return $value?->symbolicName();
    }

    private function position(
        UnifiedDataMessage $message,
        string $latitudeField,
        string $longitudeField,
    ): ?Coordinate {
        $latitude = $this->values->byName(
            message: $message,
            fieldName: $latitudeField,
        );

        $longitude = $this->values->byName(
            message: $message,
            fieldName: $longitudeField,
        );

        if (
            null === $latitude
            || null === $longitude
        ) {
            return null;
        }

        return $this->coordinates->convert(
            latitude: $latitude,
            longitude: $longitude,
            latitudeFieldName: $latitudeField,
            longitudeFieldName: $longitudeField,
        );
    }
}
