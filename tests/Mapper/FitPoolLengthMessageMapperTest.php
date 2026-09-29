<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityDetailItem;
use Youmad\Endurance\Activity\Detail\Pool\PoolLength;
use Youmad\Endurance\Activity\Detail\Pool\PoolLengthType;
use Youmad\Endurance\Activity\Telemetry\MeasurementOrigin;
use Youmad\Endurance\Activity\Telemetry\MeasurementReading;
use Youmad\Endurance\Activity\Telemetry\ScalarMeasurement;
use Youmad\Endurance\ActivityFit\Mapper\FitPoolLengthMessageMapper;
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
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final class FitPoolLengthMessageMapperTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testMapsActivePoolLengthWithStrokeAndMetrics(): void
    {
        $items = (new FitPoolLengthMessageMapper())->map(
            $this->activeLengthMessage(),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(
            ActivityDetailItem::class,
            $items[0],
        );

        $length = $items[0]->detail;

        self::assertInstanceOf(
            PoolLength::class,
            $length,
        );

        self::assertSame(
            PoolLengthType::Active,
            $length->type,
        );

        self::assertSame(
            'freestyle',
            $length->stroke,
        );

        self::assertSame(
            TemporalResolution::Second,
            $length->timelineResolution(),
        );

        self::assertSame(
            '2026-01-15T10:30:25.500000+00:00',
            $length->interval()->finishedAt
                ->toDateTimeImmutable()
                ->format('Y-m-d\TH:i:s.uP'),
        );

        self::assertSame(
            25_250_000,
            $length->interval()->timerDuration->toMicroseconds(),
        );

        $strokeCount = $this->scalar(
            $length->readings(),
            'stroke_count',
        );

        self::assertSame(18, $strokeCount->value);
        self::assertSame(
            MeasurementOrigin::Reported,
            $this->origin(
                $length->readings(),
                'stroke_count',
            ),
        );

        self::assertSame(
            1,
            $this->scalar(
                $length->readings(),
                'average_speed',
            )->value,
        );

        self::assertSame(
            42,
            $this->scalar(
                $length->readings(),
                'average_swimming_cadence',
            )->value,
        );
    }

    public function testPreservesMaskedLengthMessageIndex(): void
    {
        $items = (new FitPoolLengthMessageMapper())->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:00Z',
                    elapsedMilliseconds: 25_000,
                    timerMilliseconds: 25_000,
                ) + [
                    254 => [
                        'base_type' => 0x84,
                        'bytes' => pack('v', 0x8003),
                    ],
                    12 => ['base_type' => 0x00, 'bytes' => "\x01"],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(ActivityDetailItem::class, $items[0]);
        self::assertSame(3, $items[0]->index);
    }

    public function testMapsIdleLengthWithoutStroke(): void
    {
        $items = (new FitPoolLengthMessageMapper())->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:25Z',
                    elapsedMilliseconds: 10_000,
                    timerMilliseconds: 10_000,
                ) + [
                    0 => ['base_type' => 0x00, 'bytes' => "\x1C"],
                    1 => ['base_type' => 0x00, 'bytes' => "\x03"],
                    5 => ['base_type' => 0x84, 'bytes' => pack('v', 12)],
                    6 => ['base_type' => 0x84, 'bytes' => pack('v', 750)],
                    9 => ['base_type' => 0x02, 'bytes' => chr(36)],
                    12 => ['base_type' => 0x00, 'bytes' => "\x00"],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(
            ActivityDetailItem::class,
            $items[0],
        );

        $length = $items[0]->detail;

        self::assertInstanceOf(
            PoolLength::class,
            $length,
        );

        self::assertSame(
            PoolLengthType::Idle,
            $length->type,
        );

        self::assertNull(
            $length->stroke,
        );

        self::assertSame(
            [],
            $length->readings(),
        );
    }

    public function testMapsLengthWithLegacyStopEventType(): void
    {
        $items = (new FitPoolLengthMessageMapper())->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:00Z',
                    elapsedMilliseconds: 25_000,
                    timerMilliseconds: 25_000,
                ) + [
                    0 => ['base_type' => 0x00, 'bytes' => "\x1C"],
                    1 => ['base_type' => 0x00, 'bytes' => "\x01"],
                    12 => ['base_type' => 0x00, 'bytes' => "\x01"],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(
            ActivityDetailItem::class,
            $items[0],
        );

        self::assertInstanceOf(
            PoolLength::class,
            $items[0]->detail,
        );

        self::assertSame(
            PoolLengthType::Active,
            $items[0]->detail->type,
        );
    }

    public function testIgnoresOptionalEventMetadataWithoutProfileConstraint(): void
    {
        $items = (new FitPoolLengthMessageMapper())->map(
            $this->message(
                fields: $this->timingFields(
                    startedAt: '2026-01-15T10:30:00Z',
                    elapsedMilliseconds: 25_000,
                    timerMilliseconds: 25_000,
                ) + [
                    0 => ['base_type' => 0x00, 'bytes' => "\x00"],
                    1 => ['base_type' => 0x00, 'bytes' => "\x00"],
                    12 => ['base_type' => 0x00, 'bytes' => "\x01"],
                ],
            ),
        );

        self::assertCount(1, $items);
        self::assertInstanceOf(
            ActivityDetailItem::class,
            $items[0],
        );
        self::assertInstanceOf(
            PoolLength::class,
            $items[0]->detail,
        );
        self::assertSame(
            PoolLengthType::Active,
            $items[0]->detail->type,
        );
    }

    private function activeLengthMessage(): UnifiedDataMessage
    {
        return $this->message(
            fields: $this->timingFields(
                startedAt: '2026-01-15T10:30:00Z',
                elapsedMilliseconds: 25_500,
                timerMilliseconds: 25_250,
            ) + [
                5 => ['base_type' => 0x84, 'bytes' => pack('v', 18)],
                6 => ['base_type' => 0x84, 'bytes' => pack('v', 1000)],
                7 => ['base_type' => 0x00, 'bytes' => "\x00"],
                9 => ['base_type' => 0x02, 'bytes' => chr(42)],
                11 => ['base_type' => 0x84, 'bytes' => pack('v', 5)],
                12 => ['base_type' => 0x00, 'bytes' => "\x01"],
            ],
        );
    }

    /**
     * @return array<int, array{base_type: int, bytes: string}>
     */
    private function timingFields(
        string $startedAt,
        int $elapsedMilliseconds,
        int $timerMilliseconds,
    ): array {
        $startedAtValue = $this->fitTimestamp($startedAt);

        return [
            253 => [
                'base_type' => 0x86,
                'bytes' => pack(
                    'V',
                    $startedAtValue
                        + intdiv($elapsedMilliseconds, 1000),
                ),
            ],
            2 => [
                'base_type' => 0x86,
                'bytes' => pack('V', $startedAtValue),
            ],
            3 => [
                'base_type' => 0x86,
                'bytes' => pack('V', $elapsedMilliseconds),
            ],
            4 => [
                'base_type' => 0x86,
                'bytes' => pack('V', $timerMilliseconds),
            ],
        ];
    }

    /**
     * @param array<int, array{base_type: int, bytes: string}> $fields
     */
    private function message(array $fields): UnifiedDataMessage
    {
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
            globalMessageNumber: 101,
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

    /** @param list<MeasurementReading> $readings */
    private function scalar(
        array $readings,
        string $type,
    ): ScalarMeasurement {
        foreach ($readings as $reading) {
            if ($type !== $reading->measurement->type()->toString()) {
                continue;
            }

            self::assertInstanceOf(
                ScalarMeasurement::class,
                $reading->measurement,
            );

            return $reading->measurement;
        }

        self::fail(
            sprintf('Measurement %s was not found.', $type),
        );
    }

    /** @param list<MeasurementReading> $readings */
    private function origin(
        array $readings,
        string $type,
    ): MeasurementOrigin {
        foreach ($readings as $reading) {
            if ($type === $reading->measurement->type()->toString()) {
                return $reading->origin;
            }
        }

        self::fail(
            sprintf('Measurement %s was not found.', $type),
        );
    }

    private function fitTimestamp(string $value): int
    {
        return (new \DateTimeImmutable($value))->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }
}
