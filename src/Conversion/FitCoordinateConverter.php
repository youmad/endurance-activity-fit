<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Conversion;

use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Value\SelectedFitFieldValue;
use Youmad\Endurance\Foundation\ValueObject\Coordinate;

final readonly class FitCoordinateConverter
{
    private const float DEGREES_PER_SEMICIRCLE_UNIT =
        180.0 / 2_147_483_648.0;

    public function convert(
        SelectedFitFieldValue $latitude,
        SelectedFitFieldValue $longitude,
        string $latitudeFieldName = 'position_lat',
        string $longitudeFieldName = 'position_long',
    ): Coordinate {
        return $this->convertScalars(
            latitude: $latitude->value,
            longitude: $longitude->value,
            latitudeFieldName: $latitudeFieldName,
            longitudeFieldName: $longitudeFieldName,
        );
    }

    /** @internal Optimized Record pipeline entry point. */
    public function convertScalars(
        int|float|string $latitude,
        int|float|string $longitude,
        string $latitudeFieldName = 'position_lat',
        string $longitudeFieldName = 'position_long',
    ): Coordinate {
        return new Coordinate(
            latitude: $this->degrees(
                fieldName: $latitudeFieldName,
                value: $latitude,
            ),
            longitude: $this->degrees(
                fieldName: $longitudeFieldName,
                value: $longitude,
            ),
        );
    }

    private function degrees(
        string $fieldName,
        int|float|string $value,
    ): float {
        if (!is_int($value)) {
            throw InvalidFitActivityMessage::invalidSemicircles(fieldName: $fieldName, value: $value);
        }

        return $value
            * self::DEGREES_PER_SEMICIRCLE_UNIT;
    }
}
