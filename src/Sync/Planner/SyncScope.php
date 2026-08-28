<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Planner;

/**
 * Scope filter for a plan: all resource kinds, or a single kind. A
 * partial scope never marks the overall manifest ready (section 24).
 */
enum SyncScope: string
{
    case All = 'all';

    case Tools = 'tools';

    case Agents = 'agents';

    case Teams = 'teams';
}
