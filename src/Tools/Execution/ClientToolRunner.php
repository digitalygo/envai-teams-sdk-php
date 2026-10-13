<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Execution;

use Digitalygo\Envai\Exceptions\ApiException;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Events\AgentToolRequestEvent;
use Digitalygo\Envai\Tools\Contracts\ClientToolFactory;
use Digitalygo\Envai\Tools\Contracts\SafeToolMessageException;
use Digitalygo\Envai\Tools\Contracts\ToolInvocation;
use Digitalygo\Envai\Tools\Contracts\ToolResult;
use Digitalygo\Envai\Tools\Security\ToolRequestVerifier;
use Digitalygo\Envai\Tools\Security\VerifiedToolRequest;
use Digitalygo\Envai\Tools\State\BeginResult;
use Digitalygo\Envai\Tools\State\ClientToolCallStatus;
use Digitalygo\Envai\Tools\State\ClientToolCallStore;
use Digitalygo\Envai\Tools\State\ToolCallIdentity;
use Digitalygo\Envai\Tools\State\ToolCallStatus;
use Psr\Log\LoggerInterface;

/**
 * Executes verified client tool requests in this security-sensitive order:
 *
 * 1. verify the signature via ToolRequestVerifier; an unverified request
 *    throws ProtocolViolationException and the handler is NEVER invoked nor
 *    anything submitted;
 * 2. registry lookup; an unknown tool does not run any handler but DOES
 *    consume the call: the store is begun and a controlled
 *    `{"code":"tool_not_found",...}` error is submitted with is_error true;
 * 3. concurrency guard: when the number of handlers executing inside this
 *    runner reaches maxConcurrent, the request is refused by submitting a
 *    controlled `{"code":"tool_busy",...}` error with is_error true; the
 *    platform resumes the branch with the error, which is honest and
 *    non-blocking. The guard is process-local; cross-process at-most-once is
 *    enforced by the call store's begin();
 * 4. the call store is begun; already_running/already_resolved/
 *    already_ambiguous mean the handler MUST NOT run again and nothing is
 *    submitted (the platform already has an outcome or the call is in flight
 *    elsewhere);
 * 5. arguments are validated against the complete local JSON Schema before
 *    the handler runs, preserving object/list distinctions. Invalid
 *    arguments deliver a controlled `{"code":"invalid_arguments","violations":[...]}`
 *    error without running the handler;
 * 6. deadline: `timeout_seconds - submissionMarginSeconds` floored at 1
 *    second. PHP handlers are synchronous, so the margin check is
 *    cooperative: when the handler exceeds the deadline the real output is
 *    NOT submitted, the call is marked ambiguous and the server's own
 *    deadline auto-resolves the branch with tool_timeout;
 * 7. the result is serialized (strings as-is, ToolResult serialized once at
 *    construction) and capped at 131072 bytes; a larger output is replaced
 *    by a controlled `{"code":"output_too_large",...}` error;
 * 8. markSubmitted -> sender->submit -> markResolved. Sender failures are
 *    classified: 409 tool_already_resolved -> markResolved (the server
 *    already has our outcome), 409 tool_timed_out -> markAmbiguous,
 *    404 -> markAmbiguous, any other failure -> markAmbiguous and the run
 *    reports delivery_failed with the exception recorded; there is no blind
 *    retry;
 * 9. handler exceptions are deliverable outcomes: a controlled
 *    `{"code":"handler_error","message":...}` error is submitted through
 *    the same delivery flow. The message is generic by default
 *    ("The client tool handler failed.") and never carries raw exception
 *    text across the trust boundary; a handler that throws
 *    SafeToolMessageException opts in to exposing its own message (still
 *    UTF-8-checked and length-capped).
 *
 * Logging is deliberately restricted to call ids, tool names and outcomes;
 * arguments and outputs are never logged by default.
 */
final readonly class ClientToolRunner
{
    private const OUTPUT_LIMIT_BYTES = 131072;

    private const MIN_DEADLINE_SECONDS = 1.0;

    private const HANDLER_ERROR_MESSAGE_LIMIT = 2000;

    private const GENERIC_HANDLER_ERROR_MESSAGE = 'The client tool handler failed.';

    /**
     * Handlers currently executing inside this runner instance.
     */
    private readonly InFlightTracker $inFlight;

    public function __construct(
        private readonly ClientToolRegistry $registry,
        private readonly ClientToolFactory $factory,
        private readonly ToolRequestVerifier $verifier,
        private readonly ClientToolCallStore $callStore,
        private readonly ToolOutputSender $sender,
        private readonly ?LoggerInterface $logger = null,
        private readonly RunnerOptions $options = new RunnerOptions,
    ) {
        $this->inFlight = new InFlightTracker;
    }

    /**
     * Runs one verified tool request.
     *
     * @throws ProtocolViolationException when the signature cannot be
     *                                    verified; the handler is never
     *                                    invoked and nothing is submitted.
     */
    public function handle(AgentToolRequestEvent $event, string $chatId): ToolRunResult
    {
        if ($chatId !== $event->envelope->chatId) {
            throw new ProtocolViolationException('Client tool request chat does not match the execution chat.');
        }

        // BINDING step 1: verification is fail-closed and happens before
        // anything touches the store or the sender.
        $verified = $this->verifier->verify($event);

        $identity = new ToolCallIdentity(
            chatId: $chatId,
            callId: $verified->callId,
            toolName: $verified->toolName,
            turnId: $event->envelope->turnId,
        );

        $registered = $this->registry->lookup($verified->toolName);

        if ($registered === null) {
            return $this->unknownTool($identity, $verified->callId);
        }

        if ($this->inFlight->count() >= $this->options->maxConcurrent) {
            return $this->refuseBusy($identity, $verified->callId);
        }

        $begin = $this->callStore->begin($identity);

        if ($begin->status !== ToolCallStatus::Started) {
            return $this->skippedByStore($verified->callId, $begin);
        }

        return $this->runRegistered($event, $identity, $registered, $verified);
    }

    /**
     * Convenience over a chat event stream iteration: filters
     * AgentToolRequestEvent instances and runs them in order.
     *
     * @param  iterable<mixed>  $events
     * @return list<ToolRunResult>
     */
    public function handleEvents(iterable $events, string $chatId): array
    {
        $results = [];

        foreach ($events as $event) {
            if ($event instanceof AgentToolRequestEvent) {
                $results[] = $this->handle($event, $chatId);
            }
        }

        return $results;
    }

    private function unknownTool(ToolCallIdentity $identity, string $callId): ToolRunResult
    {
        $begin = $this->callStore->begin($identity);

        if ($begin->status !== ToolCallStatus::Started) {
            return $this->skippedByStore($callId, $begin);
        }

        $output = ToolResult::json([
            'code' => 'tool_not_found',
            'message' => sprintf('Client tool "%s" is not registered in the local registry.', $identity->toolName),
        ])->output;

        $delivery = $this->deliver($identity, $identity->chatId, $callId, $output, isError: true);

        return new ToolRunResult($callId, self::runOutcome(ToolRunOutcome::UnknownTool, $delivery), $delivery['outputSubmitted'], $delivery['storeStatus'], $delivery['error']);
    }

    private function refuseBusy(ToolCallIdentity $identity, string $callId): ToolRunResult
    {
        $begin = $this->callStore->begin($identity);

        if ($begin->status !== ToolCallStatus::Started) {
            return $this->skippedByStore($callId, $begin);
        }

        $output = ToolResult::json([
            'code' => 'tool_busy',
            'message' => 'The maximum number of concurrent client tool executions is reached; the platform resumes this branch with the error.',
        ])->output;

        $delivery = $this->deliver($identity, $identity->chatId, $callId, $output, isError: true);

        return new ToolRunResult($callId, self::runOutcome(ToolRunOutcome::ToolBusy, $delivery), $delivery['outputSubmitted'], $delivery['storeStatus'], $delivery['error']);
    }

    private function skippedByStore(string $callId, BeginResult $begin): ToolRunResult
    {
        $this->logger?->info('Client tool call skipped by the call store; the handler was not invoked.', [
            'call_id' => $callId,
            'status' => $begin->status->value,
        ]);

        return new ToolRunResult(
            $callId,
            ToolRunOutcome::SkippedByStore,
            false,
            $begin->previous?->status,
        );
    }

    private function runRegistered(
        AgentToolRequestEvent $event,
        ToolCallIdentity $identity,
        RegisteredTool $registered,
        VerifiedToolRequest $verified,
    ): ToolRunResult {
        try {
            $tool = $this->factory->create($registered->class);
        } catch (\Throwable $exception) {
            return $this->handlerError($identity, $verified->callId, $exception);
        }

        $arguments = self::toNative($verified->args);

        if (! is_array($arguments)) {
            throw new ProtocolViolationException('Verified client tool arguments must be a native array after conversion.');
        }

        $schema = $tool->schema();
        $violations = self::validateArguments($verified->args->toArray(), $schema);

        if ($violations === []) {
            $violations = ToolArgumentsValidator::validate($verified->args, $schema);
        }

        if ($violations !== []) {
            $output = ToolResult::json([
                'code' => 'invalid_arguments',
                'violations' => $violations,
            ])->output;

            $delivery = $this->deliver($identity, $identity->chatId, $verified->callId, $output, isError: true);

            return new ToolRunResult($verified->callId, self::runOutcome(ToolRunOutcome::InvalidArguments, $delivery), $delivery['outputSubmitted'], $delivery['storeStatus'], $delivery['error']);
        }

        $invocation = new ToolInvocation(
            chatId: $identity->chatId,
            turnId: $event->envelope->turnId ?? '',
            callId: $verified->callId,
            arguments: $arguments,
        );

        $this->inFlight->add($verified->callId);
        $started = hrtime(true);
        $thrown = null;
        $result = null;

        try {
            $result = $tool->handle($invocation);
        } catch (\Throwable $exception) {
            $thrown = $exception;
        } finally {
            $this->inFlight->remove($verified->callId);
        }

        $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;

        if ($elapsedSeconds > $this->deadline($registered) && ! $this->options->allowSubmissionPastDeadline) {
            $this->callStore->markAmbiguous($identity);

            $this->logger?->warning('Client tool handler exceeded the conservative submission deadline; the call is marked ambiguous.', [
                'call_id' => $verified->callId,
                'tool_name' => $registered->name,
                'elapsed_seconds' => round($elapsedSeconds, 3),
                'deadline_seconds' => $this->deadline($registered),
            ]);

            return new ToolRunResult($verified->callId, ToolRunOutcome::TimeoutExceeded, false, ClientToolCallStatus::Ambiguous);
        }

        if ($thrown !== null) {
            return $this->handlerError($identity, $verified->callId, $thrown);
        }

        $output = $result instanceof ToolResult ? $result->output : $result;
        $isError = $result instanceof ToolResult && $result->isError;

        if ($output === '') {
            $output = ToolResult::json([
                'code' => 'empty_output',
                'message' => 'The client tool returned an empty output, which the gateway does not accept.',
            ])->output;
            $delivery = $this->deliver($identity, $identity->chatId, $verified->callId, $output, isError: true);

            return new ToolRunResult($verified->callId, self::runOutcome(ToolRunOutcome::EmptyOutput, $delivery), $delivery['outputSubmitted'], $delivery['storeStatus'], $delivery['error']);
        }

        if (strlen($output) > self::OUTPUT_LIMIT_BYTES) {
            $output = ToolResult::json([
                'code' => 'output_too_large',
                'message' => sprintf('The tool output exceeds the %d byte limit.', self::OUTPUT_LIMIT_BYTES),
            ])->output;
            $isError = true;

            $delivery = $this->deliver($identity, $identity->chatId, $verified->callId, $output, $isError);

            return new ToolRunResult($verified->callId, self::runOutcome(ToolRunOutcome::OutputTooLarge, $delivery), $delivery['outputSubmitted'], $delivery['storeStatus'], $delivery['error']);
        }

        $delivery = $this->deliver($identity, $identity->chatId, $verified->callId, $output, $isError);

        return new ToolRunResult($verified->callId, self::runOutcome(ToolRunOutcome::Executed, $delivery), $delivery['outputSubmitted'], $delivery['storeStatus'], $delivery['error']);
    }

    private function handlerError(ToolCallIdentity $identity, string $callId, \Throwable $exception): ToolRunResult
    {
        $this->logger?->error('Client tool handler failed; a controlled error output is delivered.', [
            'call_id' => $callId,
            'tool_name' => $identity->toolName,
            'exception' => $exception::class,
        ]);

        $output = ToolResult::json([
            'code' => 'handler_error',
            'message' => self::sanitizeHandlerMessage($exception),
        ])->output;

        $delivery = $this->deliver($identity, $identity->chatId, $callId, $output, isError: true);

        return new ToolRunResult($callId, self::runOutcome(ToolRunOutcome::HandlerError, $delivery), $delivery['outputSubmitted'], $delivery['storeStatus'], $delivery['error']);
    }

    /**
     * Runs the markSubmitted -> submit -> markResolved sequence and maps
     * sender failures onto the store:
     *
     * - 409 tool_already_resolved: the server already has our outcome, the
     *   call is treated as resolved (warning logged);
     * - 409 tool_timed_out: the output arrived after the server deadline,
     *   the call is marked ambiguous;
     * - 404: the call was discarded server-side (terminal event, unknown or
     *   cross-chat id), the call is marked ambiguous;
     * - any other ApiException or transport failure: the call is marked
     *   ambiguous, the exception is recorded and no retry is attempted.
     *
     * @return array{outputSubmitted: bool, storeStatus: ClientToolCallStatus, error: ?string}
     */
    private function deliver(ToolCallIdentity $identity, string $chatId, string $callId, string $output, bool $isError): array
    {
        $this->callStore->markSubmitted($identity);

        try {
            $this->sender->submit($chatId, $callId, $output, $isError);
        } catch (TransportException $exception) {
            $this->logger?->error('Client tool output delivery failed on the transport; the call is marked ambiguous and no retry is attempted.', [
                'call_id' => $callId,
                'exception' => $exception::class,
            ]);

            $this->callStore->markAmbiguous($identity);

            return ['outputSubmitted' => false, 'storeStatus' => ClientToolCallStatus::Ambiguous, 'error' => $exception->getMessage()];
        } catch (ApiException $exception) {
            return $this->mapDeliveryFailure($identity, $callId, $exception);
        } catch (\Throwable $exception) {
            $this->logger?->error('Client tool output delivery failed; the call is marked ambiguous and no retry is attempted.', [
                'call_id' => $callId,
                'exception' => $exception::class,
            ]);

            $this->callStore->markAmbiguous($identity);

            return ['outputSubmitted' => false, 'storeStatus' => ClientToolCallStatus::Ambiguous, 'error' => $exception->getMessage()];
        }

        $this->callStore->markResolved($identity);

        return ['outputSubmitted' => true, 'storeStatus' => ClientToolCallStatus::Resolved, 'error' => null];
    }

    /**
     * @return array{outputSubmitted: bool, storeStatus: ClientToolCallStatus, error: ?string}
     */
    private function mapDeliveryFailure(ToolCallIdentity $identity, string $callId, ApiException $exception): array
    {
        $code = $exception->apiError()?->code;

        if ($exception->httpStatus === 409 && $code === 'tool_already_resolved') {
            $this->logger?->warning('Client tool output was already resolved server-side; the call is treated as resolved.', [
                'call_id' => $callId,
            ]);

            $this->callStore->markResolved($identity);

            return ['outputSubmitted' => true, 'storeStatus' => ClientToolCallStatus::Resolved, 'error' => null];
        }

        if ($exception->httpStatus === 409 && $code === 'tool_timed_out') {
            $this->logger?->warning('Client tool output arrived after the server deadline (409 tool_timed_out); the call is marked ambiguous.', [
                'call_id' => $callId,
            ]);

            $this->callStore->markAmbiguous($identity);

            return ['outputSubmitted' => false, 'storeStatus' => ClientToolCallStatus::Ambiguous, 'error' => null];
        }

        if ($exception->httpStatus === 404) {
            $this->logger?->info('Client tool output was rejected with 404; the call was discarded server-side and is marked ambiguous.', [
                'call_id' => $callId,
            ]);

            $this->callStore->markAmbiguous($identity);

            return ['outputSubmitted' => false, 'storeStatus' => ClientToolCallStatus::Ambiguous, 'error' => null];
        }

        $this->logger?->error('Client tool output delivery failed with an API error; the call is marked ambiguous and no retry is attempted.', [
            'call_id' => $callId,
            'http_status' => $exception->httpStatus,
            'code' => $code,
        ]);

        $this->callStore->markAmbiguous($identity);

        return ['outputSubmitted' => false, 'storeStatus' => ClientToolCallStatus::Ambiguous, 'error' => $exception->getMessage()];
    }

    /**
     * Keeps the base outcome when the delivery succeeded (or was classified
     * as a known server race) and reports delivery_failed otherwise.
     *
     * @param  array{outputSubmitted: bool, storeStatus: ClientToolCallStatus, error: ?string}  $delivery
     */
    private static function runOutcome(ToolRunOutcome $base, array $delivery): ToolRunOutcome
    {
        return $delivery['error'] !== null ? ToolRunOutcome::DeliveryFailed : $base;
    }

    private function deadline(RegisteredTool $registered): float
    {
        return max(
            self::MIN_DEADLINE_SECONDS,
            (float) $registered->timeoutSeconds - $this->options->submissionMarginSeconds,
        );
    }

    private static function toNative(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            $converted = [];

            foreach ($value->toArray() as $key => $item) {
                $converted[$key] = self::toNative($item);
            }

            return $converted;
        }

        if ($value instanceof JsonArray) {
            $converted = [];

            foreach ($value->toArray() as $item) {
                $converted[] = self::toNative($item);
            }

            return $converted;
        }

        if (is_array($value)) {
            $converted = [];

            foreach ($value as $key => $item) {
                $converted[$key] = self::toNative($item);
            }

            return $converted;
        }

        return $value;
    }

    /**
     * Validates arguments against the tool JSON Schema. The schema root is
     * guaranteed to be an object by the definitions compiler.
     *
     * Checks: every top-level property listed in `required` must be present;
     * every declared property with an explicit `type` must match one of
     * string, number, integer, boolean, array or object. additionalProperties
     * and nested schemas are subsequently enforced by ToolArgumentsValidator.
     *
     * @param  array<array-key, mixed>  $arguments
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    private static function validateArguments(array $arguments, array $schema): array
    {
        $violations = [];

        $required = $schema['required'] ?? [];

        if (is_array($required)) {
            foreach ($required as $name) {
                if (is_string($name) && ! array_key_exists($name, $arguments)) {
                    $violations[] = sprintf('Missing required property "%s".', $name);
                }
            }
        }

        $properties = $schema['properties'] ?? [];

        if (! is_array($properties)) {
            return $violations;
        }

        foreach ($properties as $name => $definition) {
            if (! is_string($name) || ! is_array($definition) || ! array_key_exists($name, $arguments)) {
                continue;
            }

            $type = $definition['type'] ?? null;

            if (! is_string($type) || self::matchesType($arguments[$name], $type)) {
                continue;
            }

            $violations[] = sprintf(
                'Property "%s" must be %s, got %s.',
                $name,
                self::typeLabel($type),
                get_debug_type($arguments[$name]),
            );
        }

        return $violations;
    }

    private static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string' => is_string($value),
            'number' => is_int($value) || is_float($value),
            'integer' => is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
            'boolean' => is_bool($value),
            'array' => $value instanceof JsonArray || (is_array($value) && array_is_list($value)),
            'object' => $value instanceof JsonObject || (is_array($value) && ! array_is_list($value)),
            'null' => $value === null,
            default => true,
        };
    }

    private static function typeLabel(string $type): string
    {
        return match ($type) {
            'string' => 'a string',
            'number' => 'a number',
            'integer' => 'an integer',
            'boolean' => 'a boolean',
            'array' => 'an array',
            'object' => 'an object',
            default => $type,
        };
    }

    /**
     * Maps a handler exception onto the message exposed to the platform.
     *
     * Fail-closed default: only SafeToolMessageException messages cross
     * the trust boundary (the handler author's explicit opt-in); every
     * other exception yields a generic message with no exception text.
     * The safe path still applies the UTF-8 and length guards.
     */
    private static function sanitizeHandlerMessage(\Throwable $exception): string
    {
        if (! $exception instanceof SafeToolMessageException) {
            return self::GENERIC_HANDLER_ERROR_MESSAGE;
        }

        $message = $exception->getMessage();

        if ($message === '') {
            return self::GENERIC_HANDLER_ERROR_MESSAGE;
        }

        if (preg_match('//u', $message) !== 1) {
            return 'The handler error message is not valid UTF-8.';
        }

        return mb_substr($message, 0, self::HANDLER_ERROR_MESSAGE_LIMIT, 'UTF-8');
    }
}
