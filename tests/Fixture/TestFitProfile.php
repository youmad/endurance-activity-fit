<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Fixture;

use Youmad\Endurance\Fit\Profile\Generated\GeneratedFitProfileSet;

/** Selects locally generated Profile data for FIT integration fixtures. */
final class TestFitProfile
{
    public static function load(): GeneratedFitProfileSet
    {
        return GeneratedFitProfileSet::load(
            messagesFile: self::messagesFile(),
            typesFile: self::typesFile(),
        );
    }

    public static function messagesFile(): string
    {
        return self::directory().'/messages.php';
    }

    public static function typesFile(): string
    {
        return self::directory().'/types.php';
    }

    private static function directory(): string
    {
        $directory = getenv('ENDURANCE_FIT_PROFILE_DIR');

        if (false !== $directory && '' !== $directory) {
            return $directory;
        }

        return dirname(__DIR__, 2).'/var/fit-profile';
    }
}
