<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Execution;

/**
 * Outcome classification of a single ClientToolRunner::handle() run.
 */
enum ToolRunOutcome: string
{
    /** The handler ran and the output was delivered (or already present). */
    case Executed = 'executed';

    /** Handler was cancelled/resolved while running; no late output delivered. */
    case Cancelled = 'cancelled';

    /** The call store already holds this call; the handler was not run. */
    case SkippedByStore = 'skipped_by_store';

    /** Verification failed; produced by callers that classify a caught ProtocolViolationException. */
    case SkippedUnverified = 'skipped_unverified';

    /** The tool is not in the local registry; a controlled error was delivered. */
    case UnknownTool = 'unknown_tool';

    /** The concurrency cap was reached; a controlled busy error was delivered. */
    case ToolBusy = 'tool_busy';

    /** The arguments did not validate against the tool schema. */
    case InvalidArguments = 'invalid_arguments';

    /** The handler exceeded the conservative submission deadline; the call was marked ambiguous. */
    case TimeoutExceeded = 'timeout_exceeded';

    /** The handler returned an empty output rejected by the gateway. */
    case EmptyOutput = 'empty_output';

    /** The output exceeded the platform limit and was replaced by a controlled error. */
    case OutputTooLarge = 'output_too_large';

    /** The handler threw; a sanitized controlled error was delivered. */
    case HandlerError = 'handler_error';

    /** The output delivery failed and the call was marked ambiguous; no retry. */
    case DeliveryFailed = 'delivery_failed';
}
