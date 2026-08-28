<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Contracts;

/**
 * Opt-in channel for a client tool handler to expose a safe, caller
 * facing error message.
 *
 * By default a handler exception never leaks its message across the
 * trust boundary: the runner submits a generic `handler_error` output
 * instead. Throwing SafeToolMessageException is the handler author's
 * explicit choice to surface the message (still subject to the UTF-8
 * and length guards) to the platform as tool output.
 */
final class SafeToolMessageException extends \RuntimeException
{
    public function __construct(string $safeMessage)
    {
        parent::__construct($safeMessage);
    }
}
