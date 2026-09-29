<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Device\DeviceDescriptor;
use Youmad\Endurance\ActivityFit\Value\SelectedFitFieldValue;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

final readonly class FitDeviceDescriptorFactory
{
    public function __construct(
        private FitFieldValueReader $values =
            new FitFieldValueReader(),
    ) {
    }

    public function create(
        UnifiedDataMessage $message,
    ): ?DeviceDescriptor {
        $manufacturer = $this->text(
            $this->values->byName(
                message: $message,
                fieldName: 'manufacturer',
            ),
        );

        $product = $this->text(
            $this->values->byName(
                message: $message,
                fieldName: 'product',
            ),
        );

        $serialNumber = $this->text(
            $this->values->byName(
                message: $message,
                fieldName: 'serial_number',
            ),
        );

        $productName = $this->text(
            $this->values->byName(
                message: $message,
                fieldName: 'product_name',
            ),
            preferSymbolic: false,
        );

        $description = $this->text(
            $this->values->byName(
                message: $message,
                fieldName: 'descriptor',
            ),
            preferSymbolic: false,
        );

        if (
            null === $manufacturer
            && null === $product
            && null === $serialNumber
            && null === $productName
            && null === $description
        ) {
            return null;
        }

        return DeviceDescriptor::create(
            manufacturer: $manufacturer,
            product: $product,
            serialNumber: $serialNumber,
            productName: $productName,
            description: $description,
        );
    }

    private function text(
        ?SelectedFitFieldValue $value,
        bool $preferSymbolic = true,
    ): ?string {
        if (null === $value) {
            return null;
        }

        $text = $preferSymbolic
            ? $value->symbolicName()
                ?? $this->scalarText($value->value)
            : $this->scalarText($value->value);

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
