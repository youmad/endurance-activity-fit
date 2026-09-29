<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
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

final class FitActivityDetailOrderingTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testSummaryFirstDetailIsEmittedAfterChronologicalRecord(): void
    {
        $items = iterator_to_array(
            FitActivityImportItemMapper::standard()->mapStream(
                [
                    $this->lengthMessage(
                        '2026-01-15T10:30:00Z',
                    ),
                    $this->recordMessage(
                        '2026-01-15T10:30:01Z',
                    ),
                ],
            ),
            false,
        );

        self::assertCount(4, $items);
        self::assertInstanceOf(
            ObservationItem::class,
            $items[0],
        );
        self::assertInstanceOf(
            ActivityDetailItem::class,
            $items[1],
        );
        self::assertInstanceOf(
            LapItem::class,
            $items[2],
        );
        self::assertInstanceOf(
            SessionItem::class,
            $items[3],
        );
    }

    private function lengthMessage(
        string $startedAt,
    ): UnifiedDataMessage {
        $timestamp = $this->fitTimestamp($startedAt);

        return $this->message(
            globalMessageNumber: 101,
            fields: [
                253 => ['base_type' => 0x86, 'bytes' => pack('V', $timestamp + 25)],
                0 => ['base_type' => 0x00, 'bytes' => "\x1C"],
                1 => ['base_type' => 0x00, 'bytes' => "\x03"],
                2 => ['base_type' => 0x86, 'bytes' => pack('V', $timestamp)],
                3 => ['base_type' => 0x86, 'bytes' => pack('V', 25_000)],
                4 => ['base_type' => 0x86, 'bytes' => pack('V', 25_000)],
                12 => ['base_type' => 0x00, 'bytes' => "\x01"],
            ],
        );
    }

    private function recordMessage(
        string $timestamp,
    ): UnifiedDataMessage {
        return $this->message(
            globalMessageNumber: 20,
            fields: [
                253 => [
                    'base_type' => 0x86,
                    'bytes' => pack(
                        'V',
                        $this->fitTimestamp($timestamp),
                    ),
                ],
                3 => ['base_type' => 0x02, 'bytes' => chr(145)],
            ],
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

        $definition = MessageDefinition::create(
            localMessageNumber: 0,
            architecture: FitArchitecture::LittleEndian,
            globalMessageNumber: $globalMessageNumber,
            standardFields: $definitions,
        );

        return FitDecoder::standard(TestFitProfile::load())->decode(
            RawDataMessage::create(
                sequenceNumber: 1,
                byteOffset: 20,
                recordHeaderByte: 0x00,
                definition: $definition,
                standardFields: $rawFields,
            ),
        );
    }

    private function fitTimestamp(string $value): int
    {
        return (new \DateTimeImmutable($value))->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }
}
