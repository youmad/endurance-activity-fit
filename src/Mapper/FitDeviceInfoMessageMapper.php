<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\DeviceItem;
use Youmad\Endurance\Activity\Application\Import\DeviceStatusItem;
use Youmad\Endurance\Activity\Device\DeviceStatusObservation;
use Youmad\Endurance\Activity\Telemetry\MeasurementType;
use Youmad\Endurance\Activity\Telemetry\MeasurementUnit;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\Activity\Telemetry\TextMeasurement;
use Youmad\Endurance\ActivityFit\Conversion\FitDateTimeConverter;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Value\SelectedFitFieldValue;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

final class FitDeviceInfoMessageMapper implements ResettableFitMessageMapper
{
    private const int DEVICE_INFO_GLOBAL_MESSAGE_NUMBER = 23;

    /**
     * @var list<array{
     *     measurement_type: string,
     *     field_name: string
     * }>
     */
    private const array TEXT_STATUS_FIELDS = [
        [
            'measurement_type' => 'device_type',
            'field_name' => 'device_type',
        ],
        [
            'measurement_type' => 'software_version',
            'field_name' => 'software_version',
        ],
        [
            'measurement_type' => 'hardware_version',
            'field_name' => 'hardware_version',
        ],
        [
            'measurement_type' => 'battery_status',
            'field_name' => 'battery_status',
        ],
        [
            'measurement_type' => 'sensor_position',
            'field_name' => 'sensor_position',
        ],
        [
            'measurement_type' => 'source_type',
            'field_name' => 'source_type',
        ],
        [
            'measurement_type' => 'ant_network',
            'field_name' => 'ant_network',
        ],
    ];

    /**
     * @var list<array{
     *     measurement_type: string,
     *     field_name: string,
     *     unit: string|null
     * }>
     */
    private const array SCALAR_STATUS_FIELDS = [
        [
            'measurement_type' => 'cum_operating_time',
            'field_name' => 'cum_operating_time',
            'unit' => 's',
        ],
        [
            'measurement_type' => 'battery_voltage',
            'field_name' => 'battery_voltage',
            'unit' => 'V',
        ],
        [
            'measurement_type' => 'battery_level',
            'field_name' => 'battery_level',
            'unit' => '%',
        ],
        [
            'measurement_type' => 'ant_transmission_type',
            'field_name' => 'ant_transmission_type',
            'unit' => null,
        ],
        [
            'measurement_type' => 'ant_device_number',
            'field_name' => 'ant_device_number',
            'unit' => null,
        ],
    ];

    public function __construct(
        private readonly FitDeviceIndexRegistry $devices =
            new FitDeviceIndexRegistry(),
        private readonly FitFieldValueReader $values =
            new FitFieldValueReader(),
        private readonly FitDateTimeConverter $dateTimes =
            new FitDateTimeConverter(),
        private readonly FitDeviceDescriptorFactory $descriptors =
            new FitDeviceDescriptorFactory(),
    ) {
    }

    public function supports(
        UnifiedDataMessage $message,
    ): bool {
        return self::DEVICE_INFO_GLOBAL_MESSAGE_NUMBER
            === $message->globalMessageNumber();
    }

    /**
     * @return list<ActivityImportItem>
     */
    public function map(
        UnifiedDataMessage $message,
    ): array {
        if (!$this->supports($message)) {
            throw new \LogicException(sprintf('FIT device_info mapper does not support global message %d.', $message->globalMessageNumber()));
        }

        $deviceIndex = $this->deviceIndex($message);

        if (null === $deviceIndex) {
            return [];
        }

        $items = [];

        $device = $this->devices->register(
            deviceIndex: $deviceIndex,
            descriptor: $this->descriptors->create($message),
        );

        if (null !== $device) {
            $items[] = new DeviceItem($device);
        }

        $status = $this->status(
            message: $message,
            deviceIndex: $deviceIndex,
        );

        if (null !== $status) {
            $items[] = new DeviceStatusItem($status);
        }

        return $items;
    }

    public function reset(): void
    {
        $this->devices->reset();
    }

    private function deviceIndex(
        UnifiedDataMessage $message,
    ): ?int {
        $value = $this->values->byName(
            message: $message,
            fieldName: 'device_index',
        );

        if (null === $value) {
            return null;
        }

        if (
            !is_int($value->value)
            || 0 > $value->value
            || 255 < $value->value
        ) {
            throw InvalidFitActivityMessage::invalidDeviceIndex($value->value);
        }

        return $value->value;
    }

    private function status(
        UnifiedDataMessage $message,
        int $deviceIndex,
    ): ?DeviceStatusObservation {
        $measurements = [];

        foreach (self::TEXT_STATUS_FIELDS as $definition) {
            $value = $this->values->byName(
                message: $message,
                fieldName: $definition['field_name'],
            );

            if (null === $value) {
                continue;
            }

            $text = $this->text($value);

            if (null === $text) {
                continue;
            }

            $measurements[] = new TextMeasurement(
                measurementType: MeasurementType::fromString(
                    $definition['measurement_type'],
                ),
                value: $text,
            );
        }

        foreach (self::SCALAR_STATUS_FIELDS as $definition) {
            $value = $this->values->byName(
                message: $message,
                fieldName: $definition['field_name'],
            );

            if (null === $value) {
                continue;
            }

            if (
                !is_int($value->value)
                && !is_float($value->value)
            ) {
                throw InvalidFitActivityMessage::invalidScalar(fieldName: $definition['field_name'], value: $value->value);
            }

            $measurements[] = new ScalarMeasurement(
                measurementType: MeasurementType::fromString(
                    $definition['measurement_type'],
                ),
                value: $value->value,
                unit: null === $definition['unit']
                    ? MeasurementUnit::none()
                    : MeasurementUnit::fromSymbol(
                        $definition['unit'],
                    ),
            );
        }

        if ([] === $measurements) {
            return null;
        }

        $deviceId = $this->devices->id(
            $deviceIndex,
        );

        $timestamp = $this->values->byName(
            message: $message,
            fieldName: 'timestamp',
        );

        return null === $timestamp
            ? DeviceStatusObservation::undated(
                $deviceId,
                ...$measurements,
            )
            : DeviceStatusObservation::at(
                $deviceId,
                $this->dateTimes->convert($timestamp),
                ...$measurements,
            );
    }

    private function text(
        SelectedFitFieldValue $value,
    ): ?string {
        $text = $value->symbolicName()
            ?? $this->scalarText($value->value);

        $text = trim($text);

        return '' === $text
            ? null
            : $text;
    }

    private function scalarText(
        int|float|string $value,
    ): string {
        if (is_float($value)) {
            return sprintf('%.15g', $value);
        }

        return (string) $value;
    }
}
