<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitRecordImportProjection;
use Youmad\Endurance\Fit\Decoder\FitDecoder;
use Youmad\Endurance\Fit\Profile\InMemoryFitProfileRegistry;
use Youmad\Endurance\Fit\Profile\InMemoryFitTypeRegistry;
use Youmad\Endurance\Fit\Raw\FitArchitecture;
use Youmad\Endurance\Fit\Raw\FitBaseType;
use Youmad\Endurance\Fit\Raw\MessageDefinition;
use Youmad\Endurance\Fit\Raw\RawDataMessage;
use Youmad\Endurance\Fit\Raw\RawFieldValue;
use Youmad\Endurance\Fit\Raw\RawStandardField;
use Youmad\Endurance\Fit\Raw\StandardFieldDefinition;
use Youmad\Endurance\Fit\Unified\UnifiedDataMessage;

final class FitFileIdOrderingTest extends TestCase
{
    /** @return iterable<string, array{list<int>, bool}> */
    public static function orders(): iterable
    {
        yield 'file_id first' => [[0, 206, 20], true];
        yield 'leading pads' => [[105, 105, 0, 206, 20], true];
        yield 'field description before file_id' => [[206, 206, 0], false];
        yield 'pad does not hide earlier metadata' => [[206, 105, 0], false];
        yield 'leading pad does not hide later metadata' => [[105, 206, 0], false];
        yield 'record before file_id' => [[20, 0], false];
        yield 'unknown data before file_id' => [[60_000, 0], false];
        yield 'later file_id is not prohibited by this check' => [[0, 206, 0], true];
        yield 'partial stream without file_id' => [[206, 20], true];
        yield 'only pads' => [[105, 105], true];
        yield 'empty stream' => [[], true];
    }

    /** @param list<int> $globalMessageNumbers */
    #[DataProvider('orders')]
    public function testValidatesOriginalDataMessageOrder(
        array $globalMessageNumbers,
        bool $accepted,
    ): void {
        $messages = [];
        foreach ($globalMessageNumbers as $index => $number) {
            $messages[] = $this->message($number, $index + 1);
        }

        if (!$accepted) {
            $this->expectException(InvalidFitActivityMessage::class);
            $this->expectExceptionMessage('FileId Message Is First');
        }

        // No projections are needed to validate the original message order.
        self::assertSame([], iterator_to_array(
            (new FitActivityImportItemMapper(mappers: []))->mapStream($messages),
        ));
    }

    public function testEmptyFusedRecordBeforeFileIdStillFailsWithSourceContext(): void
    {
        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage(
            'FIT message at sequence 3 and byte offset 103 failed Garmin required check '
            .'"FileId Message Is First": Expected file_id (global message 0) '
            .'as the first non-pad data message; found global message 20.',
        );

        iterator_to_array((new FitActivityImportItemMapper(mappers: []))->mapStream([
            $this->message(105, 1),
            new FitRecordImportProjection(items: []),
            $this->message(0, 3),
        ]));
    }

    public function testFusedRecordsAfterFileIdDoNotInvalidateLaterFileId(): void
    {
        self::assertSame([], iterator_to_array(
            (new FitActivityImportItemMapper(mappers: []))->mapStream([
                $this->message(0, 1),
                new FitRecordImportProjection(items: []),
                $this->message(0, 3),
            ]),
        ));
    }

    public function testEarlierValidStreamDoesNotHideInvalidOrderInNextStream(): void
    {
        $mapper = new FitActivityImportItemMapper(mappers: []);
        self::assertSame([], iterator_to_array($mapper->mapStream([
            $this->message(0, 1),
        ])));

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('FileId Message Is First');
        iterator_to_array($mapper->mapStream([
            $this->message(206, 1),
            $this->message(0, 2),
        ]));
    }

    public function testFailedStreamDoesNotContaminateNextStream(): void
    {
        $mapper = new FitActivityImportItemMapper(mappers: []);
        try {
            iterator_to_array($mapper->mapStream([
                $this->message(206, 1),
                $this->message(0, 2),
            ]));
            self::fail('A late file_id must fail.');
        } catch (InvalidFitActivityMessage $exception) {
            self::assertStringContainsString('FileId Message Is First', $exception->getMessage());
        }

        self::assertSame([], iterator_to_array($mapper->mapStream([
            $this->message(105, 1),
            $this->message(0, 2),
        ])));
    }

    private function message(int $globalMessageNumber, int $sequence): UnifiedDataMessage
    {
        $definitions = [];
        $fields = [];

        if (206 === $globalMessageNumber) {
            // A field_description must decode successfully before the Activity
            // mapper can validate its position relative to file_id. Supply
            // developer_data_index, field_definition_number and fit_base_type_id.
            foreach ([0 => 0, 1 => 0, 2 => 0x02] as $number => $value) {
                $definition = StandardFieldDefinition::create(
                    fieldNumber: $number,
                    size: 1,
                    baseType: FitBaseType::fromDefinitionByte(0x02),
                );
                $definitions[] = $definition;
                $fields[] = new RawStandardField(
                    definition: $definition,
                    value: RawFieldValue::fromBytes(chr($value)),
                );
            }
        }

        return (new FitDecoder(
            profiles: new InMemoryFitProfileRegistry(),
            types: new InMemoryFitTypeRegistry(),
        ))->decode(RawDataMessage::create(
            sequenceNumber: $sequence,
            byteOffset: 100 + $sequence,
            recordHeaderByte: 0x00,
            definition: MessageDefinition::create(
                localMessageNumber: 0,
                architecture: FitArchitecture::LittleEndian,
                globalMessageNumber: $globalMessageNumber,
                standardFields: $definitions,
            ),
            standardFields: $fields,
        ));
    }
}
