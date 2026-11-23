<?php

declare(strict_types=1);

namespace Endurance\ActivityFit\Value;

enum FitTimerState: string
{
    case AwaitingStart = 'awaiting_start';
    case Running = 'running';
    case Paused = 'paused';
}
