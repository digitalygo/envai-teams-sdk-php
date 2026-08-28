<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

/**
 * Kind of one orchestrator context element on restore: `compacted` is the
 * compacted context summary and must be the first element, `assistant` is an
 * orchestrator assistant message following the compact.
 */
enum OrchestratorContextKind: string
{
    case Compacted = 'compacted';
    case Assistant = 'assistant';
}
