<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

use Youmad\Endurance\Activity\ValueObject\ActivityId;

final class FitActivityImportFailed extends \RuntimeException
{
    private function __construct(
        public readonly ActivityId $activityId,
        public readonly int $bytePosition,
        public readonly int $decodedDataMessages,
        public readonly int $mappedItems,
        \Throwable $previous,
    ) {
        parent::__construct(
            message: sprintf(
                'Failed to import FIT activity %s at byte %d after decoding %d data messages and mapping %d import items: %s',
                $activityId->toString(),
                $bytePosition,
                $decodedDataMessages,
                $mappedItems,
                $previous->getMessage(),
            ),
            previous: $previous,
        );
    }

    public static function because(
        ActivityId $activityId,
        int $bytePosition,
        int $decodedDataMessages,
        int $mappedItems,
        \Throwable $previous,
    ): self {
        return new self(
            activityId: $activityId,
            bytePosition: $bytePosition,
            decodedDataMessages: $decodedDataMessages,
            mappedItems: $mappedItems,
            previous: $previous,
        );
    }
}
