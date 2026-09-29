<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Mapper;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityFit\Exception\InvalidFitActivityMessage;
use Youmad\Endurance\ActivityFit\Mapper\FitActivityImportItemMapper;
use Youmad\Endurance\ActivityFit\Mapper\FitRecordImportProjection;
use Youmad\Endurance\ActivityFit\Mapper\FitSessionLapValidator;
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

final class FitSessionLapValidatorTest extends TestCase
{
    /** @return iterable<string, array{list<int>, string}> */
    public static function missingRequiredActivityMessages(): iterable
    {
        yield 'Activity' => [[18, 19], 'Activity Message Exists'];
        yield 'Session' => [[34, 19], 'Session Message Exists'];
        yield 'Lap' => [[34, 18], 'Lap Message Exists'];
    }

    /** @param list<int> $globalMessageNumbers */
    #[DataProvider('missingRequiredActivityMessages')]
    public function testRejectsMissingRequiredGarminActivityMessage(
        array $globalMessageNumbers,
        string $check,
    ): void {
        $validator = new FitSessionLapValidator();

        foreach ($globalMessageNumbers as $globalMessageNumber) {
            $validator->observe(match ($globalMessageNumber) {
                18 => $this->session(0, 0, 1, 10_000, 10_000),
                19 => $this->lap(0, 10_000, 10_000),
                34 => $this->message(34, []),
                default => throw new \LogicException(sprintf('Unexpected FIT message number %d.', $globalMessageNumber)),
            });
        }

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage($check);

        $validator->validate(requireSummaryMessages: true);
    }

    public function testAcceptsRequiredGarminActivityMessages(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->message(34, []));
        $validator->observe($this->session(0, 0, 1, 10_000, 10_000));
        $validator->observe($this->lap(0, 10_000, 10_000));

        $validator->validate(requireSummaryMessages: true);

        self::addToAssertionCount(1);
    }

    public function testRejectsSessionWithoutRequiredGarminSport(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(
            messageIndex: 0,
            firstLapIndex: 0,
            lapCount: 1,
            timerMilliseconds: 10_000,
            elapsedMilliseconds: 10_000,
            sport: null,
        ));
        $validator->observe($this->lap(0, 10_000, 10_000));

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('Session Message Sport Exists');

        $validator->validate();
    }

    public function testAcceptsGarminIndexRangesAndOneSecondSumBoundary(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 2, 100_000, 110_000));
        $validator->observe($this->session(1, 2, 1, 50_000, 60_000));
        $validator->observe($this->lap(0, 40_000, 45_000));
        $validator->observe($this->lap(1, 61_000, 65_000));
        $validator->observe($this->lap(2, 50_000, 60_000));

        $validator->validate();

        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{bool, ?int, string}> */
    public static function invalidMessageIndexes(): iterable
    {
        yield 'missing Session index' => [true, null, 'Session Message'];
        yield 'first Session index is one' => [true, 1, 'Session Message'];
        yield 'Session selected flag is not masked' => [
            true,
            0x8000,
            'Session Message',
        ];
        yield 'missing Lap index' => [false, null, 'Lap Message'];
        yield 'first Lap index is one' => [false, 1, 'Lap Message'];
        yield 'Lap selected flag is not masked' => [
            false,
            0x8000,
            'Lap Message',
        ];
    }

    #[DataProvider('invalidMessageIndexes')]
    public function testRejectsMessageIndexThatGarminDoesNotAccept(
        bool $session,
        ?int $messageIndex,
        string $checkPrefix,
    ): void {
        $validator = new FitSessionLapValidator();
        $validator->observe(
            $session
                ? $this->session($messageIndex, 0, 0, 0, 0)
                : $this->lap($messageIndex, 0, 0),
        );

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage(
            $checkPrefix.' Valid Message Index',
        );

        $validator->validate();
    }

    public function testRejectsMissingSessionLapRange(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, null, 1, 10_000, 10_000));
        $validator->observe($this->lap(0, 10_000, 10_000));

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage(
            'first_lap_index and num_laps are required',
        );

        $validator->validate();
    }

    public function testRejectsNonAbuttingSessionLapRanges(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 1, 10_000, 10_000));
        $validator->observe($this->session(1, 2, 1, 10_000, 10_000));
        $validator->observe($this->lap(0, 10_000, 10_000));
        $validator->observe($this->lap(1, 10_000, 10_000));

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage(
            'expected first_lap_index=1; got 2',
        );

        $validator->validate();
    }

    public function testRejectsLapCountThatDoesNotCoverAllLaps(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 1, 10_000, 10_000));
        $validator->observe($this->lap(0, 10_000, 10_000));
        $validator->observe($this->lap(1, 10_000, 10_000));

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage(
            'sum of num_laps=1; decoded Lap messages=2',
        );

        $validator->validate();
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function durationSumBoundaries(): iterable
    {
        yield 'timer +1.001 seconds' => [
            'total_timer_time',
            100_000,
            101_001,
        ];
        yield 'elapsed -1.001 seconds' => [
            'total_elapsed_time',
            101_001,
            100_000,
        ];
    }

    #[DataProvider('durationSumBoundaries')]
    public function testRejectsDurationSumBeyondGarminOneSecondBoundary(
        string $fieldName,
        int $sessionMilliseconds,
        int $lapMilliseconds,
    ): void {
        $sessionTimer = 'total_timer_time' === $fieldName
            ? $sessionMilliseconds
            : 50_000;
        $sessionElapsed = 'total_elapsed_time' === $fieldName
            ? $sessionMilliseconds
            : 200_000;
        $lapTimer = 'total_timer_time' === $fieldName
            ? $lapMilliseconds
            : 50_000;
        $lapElapsed = 'total_elapsed_time' === $fieldName
            ? $lapMilliseconds
            : 200_000;
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(
            0,
            0,
            1,
            $sessionTimer,
            $sessionElapsed,
        ));
        $validator->observe($this->lap(0, $lapTimer, $lapElapsed));

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage($fieldName);
        $this->expectExceptionMessage('delta=1.001 seconds');

        $validator->validate();
    }

    public function testSkipsSessionLapChecksWhenOneMessageListIsEmpty(): void
    {
        $sessionsOnly = new FitSessionLapValidator();
        $sessionsOnly->observe($this->session(0, null, null, 1, 1));
        $sessionsOnly->validate();

        $lapsOnly = new FitSessionLapValidator();
        $lapsOnly->observe($this->lap(0, 1, 1));
        $lapsOnly->validate();

        self::addToAssertionCount(1);
    }

    public function testResetStartsNewMessageIndexSequences(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, null, null, 1, 1));
        $validator->observe($this->lap(0, 1, 1));
        $validator->reset();
        $validator->observe($this->session(0, null, null, 1, 1));

        $validator->validate();

        self::addToAssertionCount(1);
    }

    public function testResetClearsObservedActivityMessage(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->message(34, []));
        $validator->observe($this->session(0, null, null, 1, 1));
        $validator->observe($this->lap(0, 1, 1));
        $validator->reset();
        $validator->observe($this->session(0, null, null, 1, 1));
        $validator->observe($this->lap(0, 1, 1));

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('Activity Message Exists');

        $validator->validate(requireSummaryMessages: true);
    }

    public function testStreamRunsInjectedValidatorAfterReadingSource(): void
    {
        $mapper = new FitActivityImportItemMapper(
            mappers: [],
            sessionLaps: new FitSessionLapValidator(),
        );

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage(
            'Session Message Valid Message Index',
        );

        iterator_to_array(
            $mapper->mapStream([
                $this->session(null, null, null, 1, 1),
            ]),
            false,
        );
    }

    public function testCompleteActivityStreamRequiresGarminMessages(): void
    {
        $mapper = new FitActivityImportItemMapper(
            mappers: [],
            sessionLaps: new FitSessionLapValidator(),
        );

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('Activity Message Exists');

        iterator_to_array(
            $mapper->mapStream(
                messages: [
                    $this->session(0, 0, 1, 1, 1),
                    $this->lap(0, 1, 1),
                ],
                requireGarminSummaryMessages: true,
            ),
            false,
        );
    }

    /** @return iterable<string, array{int, int, int, bool}> */
    public static function lapBoundaryCases(): iterable
    {
        yield 'equal boundaries' => [40_000, 0, 40_000, true];
        yield 'one whole second late' => [40_000, 1, 40_000, true];
        yield 'two whole seconds late' => [40_000, 2, 40_000, false];
        yield '1.998 precise seconds late, one whole second' => [40_001, 1, 40_999, true];
        yield '1.002 precise seconds late, two whole seconds' => [40_999, 2, 40_001, false];
        yield 'one second before Session start' => [40_000, -1, 40_000, false];
    }

    #[DataProvider('lapBoundaryCases')]
    public function testUsesGarminWholeSecondLapContainment(
        int $sessionElapsedMilliseconds,
        int $lapStartOffset,
        int $lapElapsedMilliseconds,
        bool $accepted,
    ): void {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 1, 0, $sessionElapsedMilliseconds));
        $validator->observe($this->lap(0, 0, $lapElapsedMilliseconds, 1_000 + $lapStartOffset));

        if (!$accepted) {
            $this->expectException(InvalidFitActivityMessage::class);
            $this->expectExceptionMessage('Lap Message Start Time and Timestamp are Valid');
        }

        $validator->validate();
        self::addToAssertionCount(1);
    }

    public function testRejectsLapOutsideItsIntermediateSession(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 1, 10_000, 10_000));
        $validator->observe($this->session(1, 1, 1, 10_000, 10_000, startTime: 1_010));
        $validator->observe($this->lap(0, 10_000, 10_000, startTime: 1_002));
        $validator->observe($this->lap(1, 10_000, 10_000, startTime: 1_010));

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('Lap Message Start Time and Timestamp are Valid');
        $this->expectExceptionMessage('referenced Session message_index=0');
        $this->expectExceptionMessage('end delta=2 seconds');
        $validator->validate();
    }

    public function testRejectsLapStartingBeforeItsReferencedSessionInsideActivityEnvelope(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 1, 10_000, 10_000));
        $validator->observe($this->session(1, 1, 1, 10_000, 10_000, startTime: 1_010));
        $validator->observe($this->lap(0, 10_000, 10_000));
        $validator->observe($this->lap(1, 10_000, 10_000, startTime: 1_009));

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('Lap Message Start Time and Timestamp are Valid');
        $this->expectExceptionMessage('referenced Session message_index=1');
        $validator->validate();
    }

    public function testReportedTimestampsDoNotReplaceCalculatedEnds(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->message(18, [
            254 => 0, 25 => 0, 26 => 1, 5 => 0,
            2 => 1_000, 253 => 999, 7 => 10_000, 8 => 10_000,
        ]));
        $validator->observe($this->message(19, [
            254 => 0, 2 => 1_000, 253 => 2_000, 7 => 10_000, 8 => 10_000,
        ]));

        $validator->validate();
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{int, int}> */
    public static function missingBoundaryFields(): iterable
    {
        yield 'Session start_time' => [18, 2];
        yield 'Session timestamp' => [18, 253];
        yield 'Lap start_time' => [19, 2];
        yield 'Lap timestamp' => [19, 253];
    }

    #[DataProvider('missingBoundaryFields')]
    public function testRequiresTimingFieldsWhenBothSummaryListsExist(
        int $messageNumber,
        int $missingField,
    ): void {
        $sessionFields = [
            254 => 0, 25 => 0, 26 => 1, 5 => 0,
            2 => 1_000, 253 => 1_010, 7 => 10_000, 8 => 10_000,
        ];
        $lapFields = [254 => 0, 2 => 1_000, 253 => 1_010, 7 => 10_000, 8 => 10_000];
        if (18 === $messageNumber) {
            unset($sessionFields[$missingField]);
        } else {
            unset($lapFields[$missingField]);
        }
        $validator = new FitSessionLapValidator();
        $validator->observe($this->message(18, $sessionFields));
        $validator->observe($this->message(19, $lapFields));

        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('Lap Message Start Time and Timestamp are Valid');
        $validator->validate();
    }

    /** @return iterable<string, array{list<?int>, bool, bool}> */
    public static function firstRecordBoundaryCases(): iterable
    {
        $cases = [
            'exact start' => [[1_000, 1_010], true],
            'one second early' => [[999, 1_010], true],
            'two seconds early' => [[998, 1_010], false],
            'five seconds early' => [[995, 1_010], false],
            'missing first timestamp' => [[null, 1_000], false],
        ];
        foreach ($cases as $name => [$timestamps, $accepted]) {
            yield $name.' unified' => [$timestamps, $accepted, false];
            yield $name.' fused without items' => [$timestamps, $accepted, true];
        }
    }

    /** @param list<?int> $timestamps */
    #[DataProvider('firstRecordBoundaryCases')]
    public function testChecksFirstSourceRecordBeforeMappingOrFiltering(
        array $timestamps,
        bool $accepted,
        bool $fused,
    ): void {
        $messages = [$this->session(0, 0, 0, 0, 10_000)];
        foreach ($timestamps as $timestamp) {
            $messages[] = $fused
                ? new FitRecordImportProjection(items: [], timestamp: $timestamp)
                : $this->message(20, [253 => $timestamp]);
        }
        $mapper = new FitActivityImportItemMapper(
            mappers: [],
            sessionLaps: new FitSessionLapValidator(),
        );

        if (!$accepted) {
            $this->expectException(InvalidFitActivityMessage::class);
            $this->expectExceptionMessage('Record Message Timestamps Fall Within Session Message Times');
        }

        self::assertSame([], iterator_to_array($mapper->mapStream($messages)));
    }

    public function testFirstRecordIsComparedWithFirstSessionOnly(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 0, 0, 10_000));
        $validator->observe($this->session(1, 0, 0, 0, 10_000, startTime: 1_010));
        $validator->observe($this->message(20, [253 => 999]));
        $validator->validate();
        self::addToAssertionCount(1);
    }

    public function testFirstRecordCheckUsesSourceOrderRatherThanMinimumTimestamp(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 0, 0, 10_000));
        foreach ([1_000, 900, 1_010] as $timestamp) {
            $validator->observe($this->message(20, [253 => $timestamp]));
        }
        // Garmin's separate chronological-order check rejects the middle
        // Record. This lower-bound check only reads the first source Record.
        $validator->validate();
        self::addToAssertionCount(1);
    }

    public function testFirstRecordCheckSkipsAbsentSessionsAndResetClearsRecords(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observeRecordTimestamp(null);
        $validator->validate();
        $validator->reset();
        $validator->observe($this->session(0, 0, 0, 0, 10_000));
        $validator->validate();
        $validator->observeRecordTimestamp(1_000);
        $validator->validate();
        self::addToAssertionCount(1);
    }

    /** @return iterable<string, array{list<?int>, int, bool, bool}> */
    public static function lastRecordBoundaryCases(): iterable
    {
        $cases = [
            'exact end' => [[1_000, 1_010], 10_000, true],
            'one second late' => [[1_000, 1_011], 10_000, true],
            'two seconds late' => [[1_000, 1_012], 10_000, false],
            'fractional elapsed truncated, one second late' => [[1_000, 1_011], 10_999, true],
            'fractional elapsed is not rounded up' => [[1_000, 1_012], 10_999, false],
            'missing last timestamp' => [[1_000, null], 10_000, false],
        ];
        foreach ($cases as $name => [$timestamps, $elapsed, $accepted]) {
            yield $name.' unified' => [$timestamps, $elapsed, $accepted, false];
            yield $name.' fused without items' => [$timestamps, $elapsed, $accepted, true];
        }
    }

    /** @param list<?int> $timestamps */
    #[DataProvider('lastRecordBoundaryCases')]
    public function testChecksLastSourceRecordIncludingEmptyProjections(
        array $timestamps,
        int $elapsed,
        bool $accepted,
        bool $fused,
    ): void {
        $messages = [$this->session(0, 0, 0, 0, $elapsed)];
        foreach ($timestamps as $timestamp) {
            $messages[] = $fused
                ? new FitRecordImportProjection(items: [], timestamp: $timestamp)
                : $this->message(20, [253 => $timestamp]);
        }
        $mapper = new FitActivityImportItemMapper(
            mappers: [],
            sessionLaps: new FitSessionLapValidator(),
        );

        if (!$accepted) {
            $this->expectException(InvalidFitActivityMessage::class);
            $this->expectExceptionMessage('Record Message Timestamps Fall Within Session Message Times');
        }

        self::assertSame([], iterator_to_array($mapper->mapStream($messages)));
    }

    /** @return iterable<string, array{?int, ?int, ?int, bool}> */
    public static function lastSessionEndCases(): iterable
    {
        yield 'timestamp is not the end' => [1_000, 10_000, 9_000, false];
        yield 'missing elapsed cannot use timestamp' => [1_000, null, 1_100, false];
        yield 'missing start cannot use timestamp' => [null, 10_000, 1_100, false];
        yield 'timestamp not required by this check' => [1_000, 100_000, null, true];
    }

    #[DataProvider('lastSessionEndCases')]
    public function testLastSessionEndUsesOnlyStartAndElapsed(
        ?int $start,
        ?int $elapsed,
        ?int $timestamp,
        bool $accepted,
    ): void {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 0, 0, 10_000));
        $validator->observe($this->message(18, [
            254 => 1, 2 => $start, 7 => $elapsed, 253 => $timestamp, 5 => 0,
        ]));
        $validator->observeRecordTimestamp(1_000);
        $validator->observeRecordTimestamp(1_100);
        if (!$accepted) {
            $this->expectException(InvalidFitActivityMessage::class);
            $this->expectExceptionMessage('Record Message Timestamps Fall Within Session Message Times');
        }
        $validator->validate();
        self::addToAssertionCount(1);
    }

    public function testLastRecordCheckUsesLastSessionAndRecordInSourceOrder(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 0, 0, 10_000));
        $validator->observe($this->session(1, 0, 0, 0, 10_000, startTime: 1_010));
        foreach ([1_000, 2_000, 1_021] as $timestamp) {
            $validator->observeRecordTimestamp($timestamp);
        }
        // The middle Record belongs to the separate chronology check.
        // This boundary check must not replace the last timestamp with max().
        $validator->validate();
        self::addToAssertionCount(1);
    }

    public function testEarlierLongSessionDoesNotHideLastSessionEndViolation(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 0, 0, 100_000));
        $validator->observe($this->session(1, 0, 0, 0, 10_000, startTime: 1_010));
        $validator->observeRecordTimestamp(1_000);
        $validator->observeRecordTimestamp(1_022);
        $this->expectException(InvalidFitActivityMessage::class);
        $this->expectExceptionMessage('last Session calculated end=1020');
        $validator->validate();
    }

    public function testResetClearsLastRecordAfterBoundaryFailure(): void
    {
        $validator = new FitSessionLapValidator();
        $validator->observe($this->session(0, 0, 0, 0, 10_000));
        $validator->observeRecordTimestamp(1_000);
        $validator->observeRecordTimestamp(1_012);
        try {
            $validator->validate();
            self::fail('Expected the last Record boundary to fail.');
        } catch (InvalidFitActivityMessage $exception) {
            self::assertStringContainsString('last Record timestamp=1012', $exception->getMessage());
        }
        $validator->reset();
        $validator->observe($this->session(0, 0, 0, 0, 10_000));
        $validator->validate();
        $validator->observeRecordTimestamp(1_011);
        $validator->validate();
        self::addToAssertionCount(1);
    }

    private function session(
        ?int $messageIndex,
        ?int $firstLapIndex,
        ?int $lapCount,
        int $timerMilliseconds,
        int $elapsedMilliseconds,
        ?int $sport = 0,
        int $startTime = 1_000,
    ): UnifiedDataMessage {
        return $this->message(
            globalMessageNumber: 18,
            fields: [
                254 => $messageIndex,
                2 => $startTime,
                253 => $startTime + intdiv($elapsedMilliseconds, 1_000),
                7 => $elapsedMilliseconds,
                8 => $timerMilliseconds,
                25 => $firstLapIndex,
                26 => $lapCount,
                5 => $sport,
            ],
        );
    }

    private function lap(
        ?int $messageIndex,
        int $timerMilliseconds,
        int $elapsedMilliseconds,
        int $startTime = 1_000,
    ): UnifiedDataMessage {
        return $this->message(
            globalMessageNumber: 19,
            fields: [
                254 => $messageIndex,
                2 => $startTime,
                253 => $startTime + intdiv($elapsedMilliseconds, 1_000),
                7 => $elapsedMilliseconds,
                8 => $timerMilliseconds,
            ],
        );
    }

    /**
     * @param array<int, ?int> $fields
     */
    private function message(
        int $globalMessageNumber,
        array $fields,
    ): UnifiedDataMessage {
        static $sequence = 0;
        $definitions = [];
        $rawFields = [];

        foreach ($fields as $fieldNumber => $value) {
            if (null === $value) {
                continue;
            }

            $uint16 = in_array($fieldNumber, [254, 25, 26], true);
            $enum = 5 === $fieldNumber;
            $definition = StandardFieldDefinition::create(
                fieldNumber: $fieldNumber,
                size: $enum ? 1 : ($uint16 ? 2 : 4),
                baseType: FitBaseType::fromDefinitionByte(
                    $enum ? 0x00 : ($uint16 ? 0x84 : 0x86),
                ),
            );
            $definitions[] = $definition;
            $rawFields[] = new RawStandardField(
                definition: $definition,
                value: RawFieldValue::fromBytes(
                    $enum
                        ? pack('C', $value)
                        : ($uint16 ? pack('v', $value) : pack('V', $value)),
                ),
            );
        }

        ++$sequence;

        return FitDecoder::standard(TestFitProfile::load())->decode(
            RawDataMessage::create(
                sequenceNumber: $sequence,
                byteOffset: 20 + $sequence,
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
