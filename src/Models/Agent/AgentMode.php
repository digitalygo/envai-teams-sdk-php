<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Agent;

/**
 * Agent mode: `primary` is usable as team orchestrator, `subagent` runs
 * delegated tasks only.
 */
enum AgentMode: string
{
    case Primary = 'primary';
    case Subagent = 'subagent';
}
