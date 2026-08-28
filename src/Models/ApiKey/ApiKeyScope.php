<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ApiKey;

/**
 * Capability scope of an api key. The only current scope is
 * `completions:write`.
 */
enum ApiKeyScope: string
{
    case CompletionsWrite = 'completions:write';
}
