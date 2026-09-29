<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Import;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityFit\Import\DetectFitActivityStart;
use Youmad\Endurance\ActivityFit\Tests\Fixture\TestFitProfile;
use Youmad\Endurance\Fit\Checksum\FitCrc16;
use Youmad\Endurance\Fit\IO\ResourceFitInput;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class DetectFitActivityStartTest extends TestCase
{
    private const int FIT_EPOCH_TO_UNIX_SECONDS = 631_065_600;

    public function testUsesEarliestMappedTimestampWithoutTimerStart(): void
    {
        $recordedAt = new \DateTimeImmutable(
            '2026-01-15T10:30:01Z',
        );

        $startedAt = DetectFitActivityStart::standard(TestFitProfile::load())->detect(
            $this->input(
                $this->fitFile(
                    recordedAt: $recordedAt,
                    heartRate: 151,
                ),
            ),
        );

        self::assertTrue(
            $startedAt->equals(
                Instant::fromDateTimeImmutable($recordedAt),
            ),
        );
    }

    public function testExplicitTimerStartOverridesEarlierFallbackTimestamp(): void
    {
        $startedAt = new \DateTimeImmutable(
            '2026-01-15T10:30:00Z',
        );
        $recordedAt = new \DateTimeImmutable(
            '2026-01-15T10:29:59Z',
        );

        $detected = DetectFitActivityStart::standard(TestFitProfile::load())->detect(
            $this->input(
                $this->fitFileWithTimerStart(
                    startedAt: $startedAt,
                    recordedAt: $recordedAt,
                    heartRate: 151,
                ),
            ),
        );

        self::assertTrue(
            $detected->equals(
                Instant::fromDateTimeImmutable($startedAt),
            ),
        );
    }

    private function fitFile(
        \DateTimeImmutable $recordedAt,
        int $heartRate,
    ): string {
        $definition = "\x40"
            ."\x00"
            ."\x00"
            .pack('v', 20)
            ."\x02"
            ."\xFD\x04\x86"
            ."\x03\x01\x02";
        $data = $definition
            ."\x00"
            .pack('V', $this->fitTimestamp($recordedAt))
            .chr($heartRate);

        return $this->file($data);
    }

    private function fitFileWithTimerStart(
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $recordedAt,
        int $heartRate,
    ): string {
        $eventDefinition = "\x40"
            ."\x00"
            ."\x00"
            .pack('v', 21)
            ."\x03"
            ."\xFD\x04\x86"
            ."\x00\x01\x00"
            ."\x01\x01\x00";
        $event = "\x00"
            .pack('V', $this->fitTimestamp($startedAt))
            ."\x00"
            ."\x00";
        $recordDefinition = "\x41"
            ."\x00"
            ."\x00"
            .pack('v', 20)
            ."\x02"
            ."\xFD\x04\x86"
            ."\x03\x01\x02";
        $record = "\x01"
            .pack('V', $this->fitTimestamp($recordedAt))
            .chr($heartRate);

        return $this->file(
            $eventDefinition
            .$event
            .$recordDefinition
            .$record,
        );
    }

    private function file(string $data): string
    {
        $headerWithoutCrc = "\x0E"
            ."\x20"
            .pack('v', 21_208)
            .pack('V', strlen($data))
            .'.FIT';
        $header = $headerWithoutCrc
            .pack(
                'v',
                FitCrc16::calculate($headerWithoutCrc),
            );
        $fileWithoutTrailer = $header.$data;

        return $fileWithoutTrailer
            .pack(
                'v',
                FitCrc16::calculate($fileWithoutTrailer),
            );
    }

    private function fitTimestamp(\DateTimeImmutable $value): int
    {
        return $value->getTimestamp()
            - self::FIT_EPOCH_TO_UNIX_SECONDS;
    }

    private function input(string $bytes): ResourceFitInput
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        return new ResourceFitInput($stream);
    }
}
