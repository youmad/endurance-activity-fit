<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\ValueObject\ActivityId;

final readonly class FitActivityImportReport
{
    /** @var list<ActivityImportWarning> */
    public array $warnings;

    /** @param list<mixed> $warnings */
    public function __construct(
        public ActivityId $activityId,
        public int $protocolVersion,
        public int $profileVersion,
        public int $declaredDataSize,
        public int $decodedDataMessages,
        public FitActivityImportItemCounts $items,
        public int $finalBytePosition,
        public int $fileCrc,
        array $warnings = [],
    ) {
        $validatedWarnings = [];

        foreach ($warnings as $warning) {
            if (!$warning instanceof ActivityImportWarning) {
                throw new \InvalidArgumentException('FIT activity import report warnings must contain ActivityImportWarning values.');
            }

            $validatedWarnings[] = $warning;
        }

        $this->warnings = $validatedWarnings;

        foreach (
            [
                'protocol version' => $protocolVersion,
                'profile version' => $profileVersion,
                'declared data size' => $declaredDataSize,
                'decoded data message count' => $decodedDataMessages,
                'final byte position' => $finalBytePosition,
                'file CRC' => $fileCrc,
            ] as $name => $value
        ) {
            if (0 > $value) {
                throw new \InvalidArgumentException(sprintf('FIT activity import report %s cannot be negative.', $name));
            }
        }
    }
}
