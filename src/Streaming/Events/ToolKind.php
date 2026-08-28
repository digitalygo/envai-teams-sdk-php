<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * Discriminator of the `agent.tool.*` family: where a tool call executes.
 */
enum ToolKind: string
{
    /** Executed by the platform. */
    case Server = 'server';

    /** Executed by the client, which returns the result. */
    case Client = 'client';

    /** Wiki, skills and memory reads and writes. */
    case Knowledge = 'knowledge';

    /** Ephemeral sandbox execution (Sprite). */
    case Code = 'code';
}
