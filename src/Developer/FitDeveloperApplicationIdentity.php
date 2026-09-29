<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Developer;

use Youmad\Endurance\Fit\Developer\DeveloperDataProfile;

final readonly class FitDeveloperApplicationIdentity
{
    private function __construct(
        public FitDeveloperIdentityKind $kind,
        public string $identifier,
        public int $developerDataIndex,
        public ?int $manufacturerId,
        public ?int $applicationVersion,
    ) {
    }

    public static function fromProfile(
        DeveloperDataProfile $profile,
    ): ?self {
        $applicationId = $profile->applicationId();

        if (null !== $applicationId) {
            return new self(
                kind: FitDeveloperIdentityKind::ApplicationId,
                identifier: self::hex($applicationId),
                developerDataIndex: $profile->developerDataIndex,
                manufacturerId: $profile->manufacturerId,
                applicationVersion: $profile->applicationVersion,
            );
        }

        $developerId = $profile->developerId();

        if (null === $developerId) {
            return null;
        }

        return new self(
            kind: FitDeveloperIdentityKind::DeveloperId,
            identifier: self::hex($developerId),
            developerDataIndex: $profile->developerDataIndex,
            manufacturerId: $profile->manufacturerId,
            applicationVersion: $profile->applicationVersion,
        );
    }

    public function namespaceToken(): string
    {
        return match ($this->kind) {
            FitDeveloperIdentityKind::ApplicationId => 'application_'.$this->identifier,
            FitDeveloperIdentityKind::DeveloperId => 'developer_'.$this->identifier,
        };
    }

    /**
     * @param non-empty-list<int> $bytes
     */
    private static function hex(array $bytes): string
    {
        return strtolower(
            implode(
                '',
                array_map(
                    static fn (int $byte): string => sprintf('%02x', $byte),
                    $bytes,
                ),
            ),
        );
    }
}
