<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\Activity\Application\Import\LapItem;
use Youmad\Endurance\Activity\Application\Import\ObservationItem;
use Youmad\Endurance\Activity\Application\Import\SessionItem;
use Youmad\Endurance\Activity\Detail\Segment\SegmentEffort;
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

final class FitSegmentEffortOrderingTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testSummaryFirstSegmentEffortIsEmittedAfterChronologicalRecord(): void
    {
        $items = iterator_to_array(
            FitActivityImportItemMapper::standard()->mapStream(
                [
                    $this->segmentLapMessage(
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
            SegmentEffort::class,
            $items[1]->detail,
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

    public function testFailedSegmentEffortOutsideExplicitSessionIsSkipped(): void
    {
        $mapper = FitActivityImportItemMapper::standard();
        $items = iterator_to_array(
            $mapper->mapStream(
                [
                    $this->segmentLapMessage(
                        startedAt: '2026-01-15T10:29:30Z',
                        durationSeconds: 60,
                        status: 1,
                    ),
                    $this->sessionMessage(
                        startedAt: '2026-01-15T10:00:00Z',
                        durationSeconds: 1_800,
                    ),
                ],
            ),
            false,
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(SessionItem::class, $items[0]);

        $warnings = $mapper->warnings();
        self::assertCount(1, $warnings);
        self::assertSame(
            ActivityImportWarningCode::FailedSegmentEffortOutsideSessionSkipped,
            $warnings[0]->code,
        );
        self::assertSame(
            '2026-01-15T10:30:30.000000+00:00',
            $warnings[0]->context['segmentFinishedAt'],
        );
        self::assertSame(
            '2026-01-15T10:30:00.000000+00:00',
            $warnings[0]->context['sessionFinishedAt'],
        );
    }

    public function testFailedSegmentEffortInsideExplicitSessionIsKept(): void
    {
        $mapper = FitActivityImportItemMapper::standard();
        $items = iterator_to_array(
            $mapper->mapStream(
                [
                    $this->segmentLapMessage(
                        startedAt: '2026-01-15T10:29:00Z',
                        durationSeconds: 60,
                        status: 1,
                    ),
                    $this->sessionMessage(
                        startedAt: '2026-01-15T10:00:00Z',
                        durationSeconds: 1_800,
                    ),
                ],
            ),
            false,
        );

        self::assertCount(2, $items);
        self::assertInstanceOf(ActivityDetailItem::class, $items[0]);
        self::assertInstanceOf(SessionItem::class, $items[1]);
        self::assertSame([], $mapper->warnings());
    }

    public function testSuccessfulSegmentEffortOutsideExplicitSessionIsKept(): void
    {
        $mapper = FitActivityImportItemMapper::standard();
        $items = iterator_to_array(
            $mapper->mapStream(
                [
                    $this->segmentLapMessage(
                        startedAt: '2026-01-15T10:29:30Z',
                        durationSeconds: 60,
                        status: 0,
                    ),
                    $this->sessionMessage(
                        startedAt: '2026-01-15T10:00:00Z',
                        durationSeconds: 1_800,
                    ),
                ],
            ),
            false,
        );

        self::assertCount(2, $items);
        self::assertInstanceOf(ActivityDetailItem::class, $items[0]);
        self::assertInstanceOf(SessionItem::class, $items[1]);
        self::assertSame([], $mapper->warnings());
    }

    private function segmentLapMessage(
        string $startedAt,
        int $durationSeconds = 60,
        ?int $status = null,
    ): UnifiedDataMessage {
        $timestamp = $this->fitTimestamp($startedAt);

        $fields = [
            253 => [
                'base_type' => 0x86,
                'bytes' => pack('V', $timestamp + $durationSeconds),
            ],
            2 => [
                'base_type' => 0x86,
                'bytes' => pack('V', $timestamp),
            ],
            7 => [
                'base_type' => 0x86,
                'bytes' => pack('V', $durationSeconds * 1_000),
            ],
            8 => [
                'base_type' => 0x86,
                'bytes' => pack('V', $durationSeconds * 1_000),
            ],
        ];

        if (null !== $status) {
            $fields[64] = [
                'base_type' => 0x00,
                'bytes' => chr($status),
            ];
        }

        return $this->message(
            globalMessageNumber: 142,
            fields: $fields,
        );
    }

    private function sessionMessage(
        string $startedAt,
        int $durationSeconds,
    ): UnifiedDataMessage {
        $timestamp = $this->fitTimestamp($startedAt);

        return $this->message(
            globalMessageNumber: 18,
            fields: [
                254 => [
                    'base_type' => 0x84,
                    'bytes' => pack('v', 0),
                ],
                253 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', $timestamp + $durationSeconds),
                ],
                2 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', $timestamp),
                ],
                5 => [
                    'base_type' => 0x00,
                    'bytes' => "\x02",
                ],
                7 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', $durationSeconds * 1_000),
                ],
                8 => [
                    'base_type' => 0x86,
                    'bytes' => pack('V', $durationSeconds * 1_000),
                ],
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
                3 => [
                    'base_type' => 0x02,
                    'bytes' => chr(145),
                ],
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
