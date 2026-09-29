<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Import;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportItem;
use Youmad\Endurance\Activity\Application\Import\DeviceItem;
use Youmad\Endurance\Activity\Application\Import\DeviceStatusItem;
use Youmad\Endurance\ActivityFit\Import\FitActivityFileDecoder;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Tests\Fixture\TestFitProfile;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\IO\ResourceFitInput;

final class FitDeviceIdentityTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function decoders(): iterable
    {
        yield 'unified' => [false];
        yield 'activity' => [true];
    }

    #[DataProvider('decoders')]
    public function testLaterFileIdPreservesEarlierCreatorsSensorsAndStatusLinks(bool $activity): void
    {
        $first = $this->fileId(4, 101, 'Activity recorder')
            .$this->deviceInfo(0, 101, 'Activity creator')
            .$this->deviceInfo(1, 201, 'Activity sensor');
        $second = $this->fileId(2, 102, manufacturer: 15, product: 1)
            .$this->deviceInfo(0, 102, 'Settings creator')
            .$this->deviceInfo(1, 202, 'Settings sensor');
        $items = $this->map(self::member($first).self::member($second), $activity);

        $devices = array_values(array_filter($items, static fn ($item): bool => $item instanceof DeviceItem));
        $statuses = array_values(array_filter($items, static fn ($item): bool => $item instanceof DeviceStatusItem));
        self::assertCount(6, $devices);
        self::assertCount(4, $statuses);

        // DeviceInfo enriches only the creator introduced by its own FileId.
        self::assertTrue($devices[0]->device->id->equals($devices[1]->device->id));
        self::assertTrue($devices[3]->device->id->equals($devices[4]->device->id));
        self::assertFalse($devices[0]->device->id->equals($devices[3]->device->id));
        self::assertFalse($devices[2]->device->id->equals($devices[5]->device->id));

        // Fold emitted updates by ID, as the storage writer does. Both
        // creators and both sensors must survive, without inherited fields.
        $latest = [];
        foreach ($devices as $item) {
            $latest[$item->device->id->toString()] = $item->device;
        }
        self::assertCount(4, $latest);
        $retained = array_values($latest);
        self::assertSame(['101', '201', '102', '202'], array_map(
            static fn ($device): ?string => $device->descriptor?->serialNumber,
            $retained,
        ));
        self::assertSame(
            ['Activity creator', 'Activity sensor', 'Settings creator', 'Settings sensor'],
            array_map(static fn ($device): ?string => $device->descriptor?->description, $retained),
        );
        self::assertSame('Activity recorder', $retained[0]->descriptor?->productName);
        self::assertSame('garmin', $retained[0]->descriptor->manufacturer);
        self::assertSame('edge_840', $retained[0]->descriptor->product);
        self::assertSame('dynastream', $retained[2]->descriptor?->manufacturer);
        self::assertSame('hrm1', $retained[2]->descriptor->product);
        self::assertNull($retained[2]->descriptor->productName);
        foreach ($statuses as $index => $status) {
            self::assertTrue($status->observation->deviceId->equals($retained[$index]->id));
        }
    }

    #[DataProvider('decoders')]
    public function testMemberWithoutFileIdKeepsTheCurrentDeviceContext(bool $activity): void
    {
        $bytes = self::member(
            $this->fileId(4, 101)
            .$this->deviceInfo(1, 201, 'Sensor before boundary'),
        ).self::member($this->deviceInfo(1, 201, 'Sensor after boundary'));
        $items = $this->map($bytes, $activity);
        $devices = array_values(array_filter($items, static fn ($item): bool => $item instanceof DeviceItem));
        $statuses = array_values(array_filter($items, static fn ($item): bool => $item instanceof DeviceStatusItem));

        self::assertCount(3, $devices);
        self::assertCount(2, $statuses);
        self::assertTrue($devices[1]->device->id->equals($devices[2]->device->id));
        self::assertSame('Sensor after boundary', $devices[2]->device->descriptor?->description);
        self::assertTrue($statuses[0]->observation->deviceId->equals($statuses[1]->observation->deviceId));
    }

    #[DataProvider('decoders')]
    public function testIdenticalFileIdsAreNotAssumedToIdentifyOnePhysicalDevice(bool $activity): void
    {
        $fileId = $this->fileId(4, 101, 'Same descriptor');
        // The projection boundary is FileId, even within one physical member.
        $items = $this->map(self::member($fileId.$fileId), $activity);

        self::assertCount(2, $items);
        self::assertInstanceOf(DeviceItem::class, $items[0]);
        self::assertInstanceOf(DeviceItem::class, $items[1]);
        self::assertFalse($items[0]->device->id->equals($items[1]->device->id));
        self::assertEquals($items[0]->device->descriptor, $items[1]->device->descriptor);
    }

    #[DataProvider('decoders')]
    public function testReusedMapperDoesNotMergeIndependentInputs(bool $activity): void
    {
        $mapper = FitActivityImportItemMapper::standard();
        $bytes = self::member($this->fileId(4, 101));
        $first = $this->map($bytes, $activity, $mapper);
        $second = $this->map($bytes, $activity, $mapper);

        self::assertCount(1, $first);
        self::assertCount(1, $second);
        self::assertInstanceOf(DeviceItem::class, $first[0]);
        self::assertInstanceOf(DeviceItem::class, $second[0]);
        self::assertFalse($first[0]->device->id->equals($second[0]->device->id));
    }

    /** @return list<ActivityImportItem> */
    private function map(
        string $bytes,
        bool $activity,
        ?FitActivityImportItemMapper $mapper = null,
    ): array {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        try {
            self::assertSame(strlen($bytes), fwrite($stream, $bytes));
            rewind($stream);
            $input = new ResourceFitInput($stream);
            $profile = TestFitProfile::load();
            $decoder = $activity
                ? FitActivityFileDecoder::standard($profile)
                : FitDecoder::standard($profile);
            $file = $decoder->open($input);
            $items = iterator_to_array(
                ($mapper ?? FitActivityImportItemMapper::standard())->mapStream($file->messages()),
                false,
            );
            self::assertTrue($file->isCompleted());
            self::assertSame(strlen($bytes), $input->position());
            self::assertSame($file->trailer()->declaredCrc, $file->trailer()->calculatedCrc);

            return $items;
        } finally {
            fclose($stream);
        }
    }

    private function fileId(
        int $type,
        int $serial,
        ?string $name = null,
        int $manufacturer = 1,
        int $product = 4062,
    ): string {
        $fields = [
            0 => [0x00, chr($type)],
            1 => [0x84, pack('v', $manufacturer)],
            2 => [0x84, pack('v', $product)],
            3 => [0x8C, pack('V', $serial)],
        ];
        if (null !== $name) {
            $fields[8] = [0x07, $name."\0"];
        }

        return $this->message(0, $fields);
    }

    private function deviceInfo(int $index, int $serial, string $description): string
    {
        return $this->message(23, [
            0 => [0x02, chr($index)],
            3 => [0x8C, pack('V', $serial)],
            5 => [0x84, pack('v', 100)],
            19 => [0x07, $description."\0"],
        ]);
    }

    /** @param array<int, array{int, string}> $fields */
    private function message(int $globalMessageNumber, array $fields): string
    {
        $definition = "\x40\x00\x00".pack('vC', $globalMessageNumber, count($fields));
        $payload = "\x00";
        foreach ($fields as $number => [$baseType, $bytes]) {
            $definition .= pack('CCC', $number, strlen($bytes), $baseType);
            $payload .= $bytes;
        }

        return $definition.$payload;
    }

    private static function member(string $data): string
    {
        $bytes = pack('CCvVa4', 12, 0x20, 21_214, strlen($data), '.FIT').$data;

        return $bytes.pack('v', FitCrc16::calculate($bytes));
    }
}
