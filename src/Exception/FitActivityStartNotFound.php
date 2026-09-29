<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Exception;

final class FitActivityStartNotFound extends \RuntimeException
{
    public static function inFile(): self
    {
        return new self(
            'FIT activity start time could not be determined.',
        );
    }
}
