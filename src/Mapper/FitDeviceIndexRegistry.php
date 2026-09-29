<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Mapper;

use Youmad\Endurance\Activity\Device\ActivityDevice;
use Youmad\Endurance\Activity\Device\DeviceDescriptor;
use Youmad\Endurance\Activity\ValueObject\ActivityDeviceId;

final class FitDeviceIndexRegistry
{
    private const int MINIMUM_DEVICE_INDEX = 0;
    private const int MAXIMUM_DEVICE_INDEX = 255;

    /**
     * @var array<int, ActivityDeviceId>
     */
    private array $ids = [];

    /**
     * The presence of a key means that a DeviceItem has already been
     * published for this FIT device index. A null value represents an
     * initially unknown device.
     *
     * @var array<int, DeviceDescriptor|null>
     */
    private array $publishedDescriptors = [];

    public function register(
        int $deviceIndex,
        ?DeviceDescriptor $descriptor,
    ): ?ActivityDevice {
        $id = $this->id($deviceIndex);

        if (
            !array_key_exists(
                $deviceIndex,
                $this->publishedDescriptors,
            )
        ) {
            $this->publishedDescriptors[$deviceIndex] =
                $descriptor;

            return null === $descriptor
                ? ActivityDevice::unknown($id)
                : ActivityDevice::described(
                    id: $id,
                    descriptor: $descriptor,
                );
        }

        $current = $this
            ->publishedDescriptors[$deviceIndex];

        $merged = $this->mergeDescriptors(
            current: $current,
            incoming: $descriptor,
        );

        if (
            null === $merged
            || (
                null !== $current
                && $current->equals($merged)
            )
        ) {
            return null;
        }

        $this->publishedDescriptors[$deviceIndex] =
            $merged;

        return ActivityDevice::described(
            id: $id,
            descriptor: $merged,
        );
    }

    public function id(int $deviceIndex): ActivityDeviceId
    {
        $this->assertDeviceIndex($deviceIndex);

        return $this->ids[$deviceIndex]
            ??= ActivityDeviceId::generate();
    }

    private function assertDeviceIndex(
        int $deviceIndex,
    ): void {
        if (
            self::MINIMUM_DEVICE_INDEX > $deviceIndex
            || self::MAXIMUM_DEVICE_INDEX < $deviceIndex
        ) {
            throw new \InvalidArgumentException('FIT device index must be between 0 and 255.');
        }
    }

    private function mergeDescriptors(
        ?DeviceDescriptor $current,
        ?DeviceDescriptor $incoming,
    ): ?DeviceDescriptor {
        if (null === $incoming) {
            return $current;
        }

        if (null === $current) {
            return $incoming;
        }

        return DeviceDescriptor::create(
            manufacturer: $incoming->manufacturer
            ?? $current->manufacturer,
            product: $incoming->product
            ?? $current->product,
            serialNumber: $incoming->serialNumber
            ?? $current->serialNumber,
            productName: $incoming->productName
            ?? $current->productName,
            description: $incoming->description
            ?? $current->description,
        );
    }

    public function reset(): void
    {
        $this->ids = [];
        $this->publishedDescriptors = [];
    }
}
