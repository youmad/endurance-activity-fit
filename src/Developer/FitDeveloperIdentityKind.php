<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityFit\Developer;

enum FitDeveloperIdentityKind: string
{
    case ApplicationId = 'application_id';
    case DeveloperId = 'developer_id';
}
