<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Import;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\ActivityFit\Import\FitActivityImportWarningCollector;

final class FitActivityImportWarningCollectorTest extends TestCase
{
    public function testDeduplicatesWarningsAndPreservesFirstOccurrenceOrder(): void
    {
        $collector = new FitActivityImportWarningCollector();
        $first = new ActivityImportWarning(
            code: ActivityImportWarningCode::UnknownActivityEvent,
            message: 'Unknown event.',
            context: [
                'eventType' => 4,
                'event' => 3,
            ],
        );
        $sameWithDifferentContextOrder = new ActivityImportWarning(
            code: ActivityImportWarningCode::UnknownActivityEvent,
            message: 'Unknown event.',
            context: [
                'event' => 3,
                'eventType' => 4,
            ],
        );
        $second = new ActivityImportWarning(
            code: ActivityImportWarningCode::ActivityTimerMismatch,
            message: 'Timer mismatch.',
        );

        $collector->add($first);
        $collector->add($sameWithDifferentContextOrder);
        $collector->add($second);

        self::assertSame(
            [$first, $second],
            $collector->all(),
        );
    }

    public function testRetainsExactlyTheLifecycleWarningLimit(): void
    {
        $collector = new FitActivityImportWarningCollector();

        for ($event = 0; $event < 64; ++$event) {
            $collector->add(
                new ActivityImportWarning(
                    code: ActivityImportWarningCode::UnknownActivityEvent,
                    message: 'Unknown event.',
                    context: ['event' => $event],
                ),
            );
        }

        $warnings = $collector->all();

        self::assertCount(64, $warnings);
        self::assertNotSame(
            ActivityImportWarningCode::WarningsTruncated,
            $warnings[63]->code,
        );
    }

    public function testTruncatesUniqueWarningsWithinLifecycleLimit(): void
    {
        $collector = new FitActivityImportWarningCollector();

        for ($event = 0; $event < 70; ++$event) {
            $collector->add(
                new ActivityImportWarning(
                    code: ActivityImportWarningCode::UnknownActivityEvent,
                    message: 'Unknown event.',
                    context: ['event' => $event],
                ),
            );
        }

        $warnings = $collector->all();

        self::assertCount(64, $warnings);
        self::assertSame(
            ActivityImportWarningCode::WarningsTruncated,
            $warnings[63]->code,
        );
        self::assertSame(
            [
                'retainedCount' => 63,
                'omittedCount' => 7,
                'limit' => 64,
            ],
            $warnings[63]->context,
        );
    }

    public function testResetClearsWarningsAndDeduplicationState(): void
    {
        $collector = new FitActivityImportWarningCollector();
        $warning = new ActivityImportWarning(
            code: ActivityImportWarningCode::UnknownActivityEvent,
            message: 'Unknown event.',
        );

        $collector->add($warning);
        $collector->reset();
        $collector->add($warning);

        self::assertSame([$warning], $collector->all());
    }
}
