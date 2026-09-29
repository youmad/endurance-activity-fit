<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Detail\ActivityInterval;
use Youmad\Endurance\Activity\Detail\Segment\SegmentEffort;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityFit\Conversion\FitCoordinateConverter;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperMeasurementMapper;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Segment\FitSegmentEffortMeasurementRegistry;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;

final readonly class FitSegmentLapMessageMapper implements FitMessageMapper
{
    private const int SEGMENT_LAP_GLOBAL_MESSAGE_NUMBER = 142;

    public function __construct(
        private FitSummaryTimingResolver $timing =
            new FitSummaryTimingResolver(),
        private FitFieldValueReader $values =
            new FitFieldValueReader(),
        private FitCoordinateConverter $coordinates =
            new FitCoordinateConverter(),
        private FitSegmentEffortMeasurementRegistry $measurements =
            new FitSegmentEffortMeasurementRegistry(),
        private FitMeasurementReadingFactory $readings =
            new FitMeasurementReadingFactory(),
        private FitDeveloperMeasurementMapper $developerMeasurements =
            new FitDeveloperMeasurementMapper(),
    ) {
    }

    public function supports(
        UnifiedDataMessage $message,
    ): bool {
        return self::SEGMENT_LAP_GLOBAL_MESSAGE_NUMBER
            === $message->globalMessageNumber();
    }

    /**
     * @return list<ActivityImportItem>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array {
        if (!$this->supports($message)) {
            throw new \LogicException(sprintf('FIT segment lap mapper does not support global message %d.', $message->globalMessageNumber()));
        }

        $timing = $this->timing->resolve(
            message: $message,
            messageName: 'segment_lap',
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
            message: $message,
            fieldName: 'sport',
        );

        return [new ActivityDetailItem(
            SegmentEffort::create(
                interval: ActivityInterval::create(
                    startedAt: $timing->startedAt,
                    finishedAt: $timing->finishedAt,
                    timerDuration: $timing->timerDuration,
                ),
                segmentId: $this->text(
                    message: $message,
                    fieldName: 'uuid',
                ),
                name: $this->text(
                    message: $message,
                    fieldName: 'name',
                ),
                status: $this->symbolic(
                    message: $message,
                    fieldName: 'status',
                ),
                sport: $sport,
                subSport: null === $sport
                    ? null
                    : $this->symbolic(
                        message: $message,
                        fieldName: 'sub_sport',
                    ),
                manufacturer: $this->symbolic(
                    message: $message,
                    fieldName: 'manufacturer',
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
            ),
        )];
    }

    private function symbolic(
        UnifiedDataMessage $message,
        string $fieldName,
    ): ?string {
        return $this->values
            ->byName(
                message: $message,
                fieldName: $fieldName,
            )
            ?->symbolicName();
    }

    private function text(
        UnifiedDataMessage $message,
        string $fieldName,
    ): ?string {
        $value = $this->values->byName(
            message: $message,
            fieldName: $fieldName,
        );

        if (null === $value) {
            return null;
        }

        if (!is_string($value->value)) {
            throw InvalidFitActivityMessage::invalidText(fieldName: $fieldName, value: $value->value);
        }

        $text = trim($value->value);

        return '' === $text
            ? null
            : $text;
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
