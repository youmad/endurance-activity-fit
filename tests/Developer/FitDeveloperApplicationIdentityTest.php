<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Tests\Developer;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperApplicationIdentity;
use Youmad\Endurance\ActivityFit\Developer\FitDeveloperIdentityKind;
use Youmad\Endurance\Fit\Developer\DeveloperDataProfile;

final class FitDeveloperApplicationIdentityTest extends TestCase
{
    public function testPrefersApplicationId(): void
    {
        $identity = FitDeveloperApplicationIdentity::fromProfile(
            DeveloperDataProfile::create(
                developerDataIndex: 2,
                developerId: range(1, 16),
                applicationId: range(0xA0, 0xAF),
                manufacturerId: 1,
                applicationVersion: 110,
            ),
        );

        self::assertNotNull($identity);
        self::assertSame(
            FitDeveloperIdentityKind::ApplicationId,
            $identity->kind,
        );
        self::assertSame(
            'a0a1a2a3a4a5a6a7a8a9aaabacadaeaf',
            $identity->identifier,
        );
        self::assertSame(
            'application_a0a1a2a3a4a5a6a7a8a9aaabacadaeaf',
            $identity->namespaceToken(),
        );
    }

    public function testUsesDeveloperIdAsStableFallback(): void
    {
        $identity = FitDeveloperApplicationIdentity::fromProfile(
            DeveloperDataProfile::create(
                developerDataIndex: 2,
                developerId: [1, 2, 15, 16],
            ),
        );

        self::assertNotNull($identity);
        self::assertSame(
            FitDeveloperIdentityKind::DeveloperId,
            $identity->kind,
        );
        self::assertSame(
            '01020f10',
            $identity->identifier,
        );
    }

    public function testCannotInventStableIdentityFromFileLocalIndex(): void
    {
        self::assertNull(
            FitDeveloperApplicationIdentity::fromProfile(
                DeveloperDataProfile::create(
                    developerDataIndex: 2,
                ),
            ),
        );
    }
}
