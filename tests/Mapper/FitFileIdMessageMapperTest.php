<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\DeviceItem;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Tests\Fixture\TestFitProfile;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\FitBaseType;
use Youmad\Endurance\Fit\Raw\MessageDefinition;
use Youmad\Endurance\Fit\Raw\RawDataMessage;
use Youmad\Endurance\Fit\Raw\RawFieldValue;
use Youmad\Endurance\Fit\Raw\RawStandardField;
use Youmad\Endurance\Fit\Raw\StandardFieldDefinition;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

final class FitFileIdMessageMapperTest extends TestCase
{
    public function testMapsFileCreatorAndMergesLaterDeviceInfo(): void
    {
        $mapper = FitActivityImportItemMapper::standard();

        $fileItems = $mapper->map(
            $this->message(
                globalMessageNumber: 0,
                fields: [
                    0 => ['base_type' => 0x00, 'bytes' => "\x04"],
                    1 => ['base_type' => 0x84, 'bytes' => pack('v', 1)],
                    2 => ['base_type' => 0x84, 'bytes' => pack('v', 4062)],
                    3 => ['base_type' => 0x8C, 'bytes' => pack('V', 123456789)],
                    8 => ['base_type' => 0x07, 'bytes' => "Edge 840\0"],
                ],
            ),
        );

        self::assertCount(1, $fileItems);
        self::assertInstanceOf(DeviceItem::class, $fileItems[0]);
        self::assertSame(
            'garmin',
            $fileItems[0]->device->descriptor?->manufacturer,
        );
        self::assertSame(
            'edge_840',
            $fileItems[0]->device->descriptor->product,
        );

        $deviceItems = $mapper->map(
            $this->message(
                globalMessageNumber: 23,
                fields: [
                    0 => ['base_type' => 0x02, 'bytes' => "\x00"],
                    2 => ['base_type' => 0x84, 'bytes' => pack('v', 1)],
                    4 => ['base_type' => 0x84, 'bytes' => pack('v', 4062)],
                    19 => ['base_type' => 0x07, 'bytes' => "Primary head unit\0"],
                ],
            ),
        );

        self::assertCount(1, $deviceItems);
        self::assertInstanceOf(DeviceItem::class, $deviceItems[0]);
        self::assertTrue(
            $fileItems[0]->device->id->equals(
                $deviceItems[0]->device->id,
            ),
        );
        self::assertSame(
            'Primary head unit',
            $deviceItems[0]->device->descriptor?->description,
        );
    }

    public function testRejectsNonActivityFile(): void
    {
        $this->expectException(
            InvalidFitActivityMessage::class,
        );
        $this->expectExceptionMessage(
            'does not support file type workout',
        );

        FitActivityImportItemMapper::standard()->map(
            $this->message(
                globalMessageNumber: 0,
                fields: [
                    0 => ['base_type' => 0x00, 'bytes' => "\x05"],
                ],
            ),
        );
    }

    /** @return iterable<string, array{?int}> */
    public static function laterFileTypes(): iterable
    {
        yield 'settings' => [2];
        yield 'device' => [1];
        yield 'workout' => [5];
        yield 'missing type' => [null];
        yield 'invalid enum sentinel' => [255];
    }

    #[DataProvider('laterFileTypes')]
    public function testLaterFileIdDoesNotRepeatTypeValidation(?int $type): void
    {
        $fields = [8 => ['base_type' => 0x07, 'bytes' => "Later metadata\0"]];
        if (null !== $type) {
            $fields[0] = ['base_type' => 0x00, 'bytes' => chr($type)];
        }

        $items = iterator_to_array(FitActivityImportItemMapper::standard()->mapStream([
            $this->fileId(4),
            $this->message(globalMessageNumber: 0, fields: $fields),
        ]), false);

        // Each FileId projects its own creator without repeating type validation.
        self::assertCount(2, $items);
        self::assertInstanceOf(DeviceItem::class, $items[0]);
        self::assertInstanceOf(DeviceItem::class, $items[1]);
        self::assertFalse($items[0]->device->id->equals($items[1]->device->id));
        self::assertSame('Later metadata', $items[1]->device->descriptor?->productName);
    }

    #[DataProvider('laterFileTypes')]
    public function testFirstFileIdStillRequiresActivityType(?int $type): void
    {
        $this->expectException(InvalidFitActivityMessage::class);
        iterator_to_array(FitActivityImportItemMapper::standard()->mapStream([
            $this->fileId($type),
            $this->fileId(4),
        ]));
    }

    public function testNextStreamChecksItsOwnFirstFileId(): void
    {
        $mapper = FitActivityImportItemMapper::standard();
        self::assertCount(2, iterator_to_array($mapper->mapStream([
            $this->fileId(4),
            $this->fileId(2),
        ])));

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('does not support file type settings');
        iterator_to_array($mapper->mapStream([$this->fileId(2)]));
    }

    public function testInterruptedStreamDoesNotDisableNextStreamTypeCheck(): void
    {
        $mapper = FitActivityImportItemMapper::standard();
        $messages = function (): iterable {
            yield $this->fileId(4);
            throw new \RuntimeException('Interrupted source');
        };
        try {
            iterator_to_array($mapper->mapStream($messages()));
            self::fail('The source failure must propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Interrupted source', $exception->getMessage());
        }

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('does not support file type settings');
        iterator_to_array($mapper->mapStream([$this->fileId(2)]));
    }

    public function testDirectMappingUsesFirstFileIdUntilExplicitReset(): void
    {
        $mapper = FitActivityImportItemMapper::standard();
        self::assertCount(1, $mapper->map($this->fileId(4)));
        self::assertCount(1, $mapper->map($this->fileId(2)));
        $mapper->reset();

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('does not support file type settings');
        $mapper->map($this->fileId(2));
    }

    public function testRejectedFirstFileIdDoesNotDisableTypeCheck(): void
    {
        $mapper = FitActivityImportItemMapper::standard();
        try {
            $mapper->map($this->fileId(2));
            self::fail('An initial settings FileId must fail.');
        } catch (InvalidFitActivityMessage $exception) {
            self::assertStringContainsString('does not support file type settings', $exception->getMessage());
        }

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('does not support file type workout');
        $mapper->map($this->fileId(5));
    }

    private function fileId(?int $type): UnifiedDataMessage
    {
        return $this->message(
            globalMessageNumber: 0,
            fields: null === $type
                ? []
                : [0 => ['base_type' => 0x00, 'bytes' => chr($type)]],
        );
    }

    /**
     * @param array<int, array{base_type: int, bytes: string}> $fields
     */
    private function message(
        int $globalMessageNumber,
        array $fields,
    ): UnifiedDataMessage {
        $definitions = [];
        $rawFields = [];

        foreach ($fields as $fieldNumber => $field) {
            $definition = StandardFieldDefinition::create(
                fieldNumber: $fieldNumber,
                size: strlen($field['bytes']),
                baseType: FitBaseType::fromDefinitionByte(
                    $field['base_type'],
                ),
            );

            $definitions[] = $definition;
            $rawFields[] = new RawStandardField(
                definition: $definition,
                value: RawFieldValue::fromBytes(
                    $field['bytes'],
                ),
            );
        }

        return FitDecoder::standard(TestFitProfile::load())->decode(
            RawDataMessage::create(
                sequenceNumber: 1,
                byteOffset: 20,
                recordHeaderByte: 0x00,
                definition: MessageDefinition::create(
                    localMessageNumber: 0,
                    architecture: FitArchitecture::LittleEndian,
                    globalMessageNumber: $globalMessageNumber,
                    standardFields: $definitions,
                ),
                standardFields: $rawFields,
            ),
        );
    }
}
