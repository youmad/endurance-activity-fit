<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;

final class FitActivityImportWarningCollector
{
    /**
     * The lifecycle accepts at most 64 warnings. When the source exceeds that
     * limit, the final public slot is replaced by a structured truncation
     * warning so malformed optional metadata cannot fail finalization.
     */
    private const int WARNING_LIMIT = 64;
    private const int RETAINED_WARNING_LIMIT_WITH_TRUNCATION = 63;

    /** @var list<ActivityImportWarning> */
    private array $warnings = [];

    /** @var array<string, true> */
    private array $seen = [];

    private int $omittedWarningCount = 0;

    public function reset(): void
    {
        $this->warnings = [];
        $this->seen = [];
        $this->omittedWarningCount = 0;
    }

    public function add(ActivityImportWarning $warning): void
    {
        $key = self::deduplicationKey($warning);

        if (isset($this->seen[$key])) {
            return;
        }

        $this->seen[$key] = true;

        if (self::WARNING_LIMIT <= count($this->warnings)) {
            ++$this->omittedWarningCount;

            return;
        }

        $this->warnings[] = $warning;
    }

    /** @return list<ActivityImportWarning> */
    public function all(): array
    {
        if (0 === $this->omittedWarningCount) {
            return $this->warnings;
        }

        $retained = array_slice(
            $this->warnings,
            0,
            self::RETAINED_WARNING_LIMIT_WITH_TRUNCATION,
        );
        $omittedWarningCount = $this->omittedWarningCount
            + count($this->warnings)
            - count($retained);

        return [
            ...$retained,
            new ActivityImportWarning(
                code: ActivityImportWarningCode::WarningsTruncated,
                message: 'Additional import warnings were omitted '
                    .'after the retained warning limit was reached.',
                context: [
                    'retainedCount' => count($retained),
                    'omittedCount' => $omittedWarningCount,
                    'limit' => self::WARNING_LIMIT,
                ],
            ),
        ];
    }

    private static function deduplicationKey(
        ActivityImportWarning $warning,
    ): string {
        $context = $warning->context;
        ksort($context);

        return hash(
            'sha256',
            serialize([
                'code' => $warning->code->value,
                'message' => $warning->message,
                'context' => $context,
            ]),
        );
    }
}
