<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Agent;

/**
 * Agent mode: `primary` agents act as team orchestrators, `subagent`
 * agents only run delegated tasks.
 */
enum AgentMode: string
{
    case Primary = 'primary';

    case Subagent = 'subagent';
}
