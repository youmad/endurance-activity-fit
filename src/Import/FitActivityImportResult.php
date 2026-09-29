<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityImportResult;

final readonly class FitActivityImportResult
{
    private function __construct(
        public ActivityImportResult $activityImport,
        public ?FitActivityImportReport $report,
    ) {
    }

    public static function imported(
        ActivityImportResult $activityImport,
        FitActivityImportReport $report,
    ): self {
        if (!$activityImport->isImported()) {
            throw new \LogicException('An imported FIT result requires an imported activity result.');
        }

        return new self(
            activityImport: $activityImport,
            report: $report,
        );
    }

    public static function alreadyImported(
        ActivityImportResult $activityImport,
    ): self {
        if ($activityImport->isImported()) {
            throw new \LogicException('An already-imported FIT result requires a duplicate activity result.');
        }

        return new self(
            activityImport: $activityImport,
            report: null,
        );
    }

    public function isImported(): bool
    {
        return $this->activityImport->isImported();
    }
}
