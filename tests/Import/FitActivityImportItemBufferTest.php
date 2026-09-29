<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Import;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleAction;
use Youmad\Endurance\Activity\Application\Import\ActivityLifecycleItem;
use Youmad\Endurance\ActivityFit\Import\FitActivityImportItemBuffer;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class FitActivityImportItemBufferTest extends TestCase
{
    public function testReplaysItemsWithoutSharingObjectIdentity(): void
    {
        $buffer = new FitActivityImportItemBuffer();
        $item = new ActivityLifecycleItem(
            action: ActivityLifecycleAction::Start,
            occurredAt: Instant::fromDateTimeImmutable(
                new \DateTimeImmutable('2026-01-15T10:30:00Z'),
            ),
        );

        try {
            $buffer->append($item);

            $firstReplay = iterator_to_array($buffer->items());
            $secondReplay = iterator_to_array($buffer->items());
        } finally {
            $buffer->close();
        }

        self::assertCount(1, $firstReplay);
        self::assertCount(1, $secondReplay);
        self::assertInstanceOf(
            ActivityLifecycleItem::class,
            $firstReplay[0],
        );
        self::assertInstanceOf(
            ActivityLifecycleItem::class,
            $secondReplay[0],
        );
        self::assertNotSame($item, $firstReplay[0]);
        self::assertNotSame($firstReplay[0], $secondReplay[0]);
        self::assertSame(
            ActivityLifecycleAction::Start,
            $firstReplay[0]->action,
        );
        self::assertTrue(
            $item->occurredAt->equals($firstReplay[0]->occurredAt),
        );
    }

    public function testReplaysItemsAcrossChunkBoundariesInAppendOrder(): void
    {
        $buffer = new FitActivityImportItemBuffer();
        $startedAt = new \DateTimeImmutable('2026-01-15T10:30:00Z');

        try {
            for ($sequence = 0; $sequence < 23; ++$sequence) {
                $buffer->append(
                    new ActivityLifecycleItem(
                        action: ActivityLifecycleAction::Start,
                        occurredAt: Instant::fromDateTimeImmutable(
                            $startedAt->modify(sprintf('+%d seconds', $sequence)),
                        ),
                    ),
                );
            }

            $firstReplay = iterator_to_array($buffer->items());
            $secondReplay = iterator_to_array($buffer->items());
        } finally {
            $buffer->close();
        }

        self::assertCount(23, $firstReplay);
        self::assertCount(23, $secondReplay);

        foreach ($firstReplay as $sequence => $item) {
            self::assertInstanceOf(ActivityLifecycleItem::class, $item);
            self::assertTrue(
                $item->occurredAt->equals(
                    Instant::fromDateTimeImmutable(
                        $startedAt->modify(sprintf('+%d seconds', $sequence)),
                    ),
                ),
            );
            self::assertNotSame($item, $secondReplay[$sequence]);
        }
    }
}
