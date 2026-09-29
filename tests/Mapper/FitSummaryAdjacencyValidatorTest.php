<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Mapper\FitSummaryAdjacencyValidator;
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

final class FitSummaryAdjacencyValidatorTest extends TestCase
{
    /** @return iterable<string, array{int, int, bool}> */
    public static function boundaries(): iterable
    {
        foreach ([18 => 'session', 19 => 'lap'] as $number => $name) {
            yield $name.' +2s gap' => [$number, 1_012, true];
            yield $name.' +3s gap' => [$number, 1_013, false];
            yield $name.' -2s overlap' => [$number, 1_008, true];
            yield $name.' -3s overlap' => [$number, 1_007, false];
        }
    }

    #[DataProvider('boundaries')]
    public function testMatchesGarminWholeSecondTwoSecondBoundary(
        int $globalMessageNumber,
        int $secondStartTime,
        bool $accepted,
    ): void {
        $validator = new FitSummaryAdjacencyValidator();
        $validator->validate($this->message(
            globalMessageNumber: $globalMessageNumber,
            sequence: 1,
            startTime: 1_000,
            elapsedMilliseconds: 10_999,
        ));

        if (!$accepted) {
            $this->expectException(InvalidFitActivityMessage::class);
            $this->expectExceptionMessage('whole-second delta=3');
        }

        $validator->validate($this->message(
            globalMessageNumber: $globalMessageNumber,
            sequence: 2,
            startTime: $secondStartTime,
            elapsedMilliseconds: 5_000,
        ));

        if ($accepted) {
            self::addToAssertionCount(1);
        }
    }

    public function testTracksLapAndSessionSequencesIndependently(): void
    {
        $validator = new FitSummaryAdjacencyValidator();

        $validator->validate($this->message(18, 1, 1_000, 10_000));
        $validator->validate($this->message(19, 2, 2_000, 20_000));
        $validator->validate($this->message(18, 3, 1_010, 10_000));
        $validator->validate($this->message(19, 4, 2_020, 20_000));

        self::addToAssertionCount(1);
    }

    public function testResetStartsANewSequence(): void
    {
        $validator = new FitSummaryAdjacencyValidator();
        $validator->validate($this->message(19, 1, 1_000, 10_000));
        $validator->reset();
        $validator->validate($this->message(19, 2, 9_000, 10_000));

        self::addToAssertionCount(1);
    }

    private function message(
        int $globalMessageNumber,
        int $sequence,
        int $startTime,
        int $elapsedMilliseconds,
    ): UnifiedDataMessage {
        $start = StandardFieldDefinition::create(
            fieldNumber: 2,
            size: 4,
            baseType: FitBaseType::fromDefinitionByte(0x86),
        );
        $elapsed = StandardFieldDefinition::create(
            fieldNumber: 7,
            size: 4,
            baseType: FitBaseType::fromDefinitionByte(0x86),
        );

        return FitDecoder::standard(TestFitProfile::load())->decode(
            RawDataMessage::create(
                sequenceNumber: $sequence,
                byteOffset: 20 + $sequence,
                recordHeaderByte: 0x00,
                definition: MessageDefinition::create(
                    localMessageNumber: 0,
                    architecture: FitArchitecture::LittleEndian,
                    globalMessageNumber: $globalMessageNumber,
                    standardFields: [$start, $elapsed],
                ),
                standardFields: [
                    new RawStandardField(
                        definition: $start,
                        value: RawFieldValue::fromBytes(pack('V', $startTime)),
                    ),
                    new RawStandardField(
                        definition: $elapsed,
                        value: RawFieldValue::fromBytes(
                            pack('V', $elapsedMilliseconds),
                        ),
                    ),
                ],
            ),
        );
    }
}
