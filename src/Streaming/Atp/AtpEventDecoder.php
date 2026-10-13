<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Atp;

use Digitalygo\Envai\Exceptions\ProtocolVersionException;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Events\AgentArtifactEvent;
use Digitalygo\Envai\Streaming\Events\AgentContextCompactedEvent;
use Digitalygo\Envai\Streaming\Events\AgentContextCompactingEvent;
use Digitalygo\Envai\Streaming\Events\AgentDelegatedEvent;
use Digitalygo\Envai\Streaming\Events\AgentDoneEvent;
use Digitalygo\Envai\Streaming\Events\AgentEscalateEvent;
use Digitalygo\Envai\Streaming\Events\AgentEscalationResultEvent;
use Digitalygo\Envai\Streaming\Events\AgentFailedEvent;
use Digitalygo\Envai\Streaming\Events\AgentInstance;
use Digitalygo\Envai\Streaming\Events\AgentPlanEvent;
use Digitalygo\Envai\Streaming\Events\AgentReasoningDeltaEvent;
use Digitalygo\Envai\Streaming\Events\AgentReasoningDoneEvent;
use Digitalygo\Envai\Streaming\Events\AgentStartedEvent;
use Digitalygo\Envai\Streaming\Events\AgentTextDeltaEvent;
use Digitalygo\Envai\Streaming\Events\AgentTextDoneEvent;
use Digitalygo\Envai\Streaming\Events\AgentToolCompleteEvent;
use Digitalygo\Envai\Streaming\Events\AgentToolDeltaEvent;
use Digitalygo\Envai\Streaming\Events\AgentToolRequestEvent;
use Digitalygo\Envai\Streaming\Events\AgentToolResultEvent;
use Digitalygo\Envai\Streaming\Events\AgentToolStartEvent;
use Digitalygo\Envai\Streaming\Events\ApprovalExpireEvent;
use Digitalygo\Envai\Streaming\Events\ApprovalRequestEvent;
use Digitalygo\Envai\Streaming\Events\ApprovalResponseEvent;
use Digitalygo\Envai\Streaming\Events\AtpEvent;
use Digitalygo\Envai\Streaming\Events\EventEnvelope;
use Digitalygo\Envai\Streaming\Events\PingEvent;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Streaming\Events\ToolKind;
use Digitalygo\Envai\Streaming\Events\TurnCancelledEvent;
use Digitalygo\Envai\Streaming\Events\TurnCompletedEvent;
use Digitalygo\Envai\Streaming\Events\TurnFailedEvent;
use Digitalygo\Envai\Streaming\Events\TurnStartedEvent;
use Digitalygo\Envai\Streaming\Events\TurnSteeredEvent;
use Digitalygo\Envai\Streaming\Events\UnknownEvent;

/**
 * Decodes one ATP event from its JSON wire payload.
 *
 * Rules, in order: the payload must be a JSON object (else
 * ProtocolViolationException); `v` must be the integer 2 (else
 * ProtocolVersionException, checked before any payload interpretation); an
 * envelope that is not structurally valid raises ProtocolViolationException;
 * an unknown `type` becomes UnknownEvent with the payload untouched; a known
 * `type` becomes its typed event, or ProtocolViolationEvent listing every
 * payload violation when the payload does not validate; `ping` additionally
 * requires `seq == 0` and null `turn_id`/`agent`.
 *
 * Additive unknown fields are tolerated at every level and preserved in the
 * matching `extra` property.
 *
 * `agent.tool.request` `args` is preserved wire-faithfully: the alpha
 * gateway omits the `args` field when the LLM passes no arguments and
 * signs the preimage over `{}`, so absent or null `args` decode to an
 * empty object; nested objects and arrays keep their JSON semantics
 * (`{}` vs `[]`) at every depth via the typed JsonObject/JsonArray
 * structure.
 */
final class AtpEventDecoder
{
    public function decode(string $json): AtpEvent
    {
        $tree = $this->decodeObject($json);

        /** @var array<string, mixed> $decoded */
        $decoded = self::assoc($tree);
        $this->assertProtocolVersion($decoded);

        $envelope = $this->buildEnvelope($decoded);

        /** @var array<string, mixed> $data */
        $data = $decoded['data'];

        // The decoded object tree keeps the wire distinction between `{}`
        // and `[]` that the associative form loses; args-bearing events
        // read their args from it.
        $dataNode = get_object_vars($tree)['data'] ?? null;
        $treeData = $dataNode instanceof \stdClass ? $dataNode : new \stdClass;

        if ($envelope->type === 'ping') {
            return $this->decodePing($envelope, $data);
        }

        if ($envelope->seq < 1) {
            return new ProtocolViolationEvent(
                $envelope,
                [sprintf('non-ping event "%s" must use a positive seq, got %d.', $envelope->type, $envelope->seq)],
                $data,
            );
        }

        $event = match ($envelope->type) {
            'turn.started' => $this->decodeTurnStarted($envelope, $data),
            'turn.completed' => $this->decodeTurnCompleted($envelope, $data),
            'turn.cancelled' => $this->decodeTurnCancelled($envelope, $data),
            'turn.failed' => $this->decodeTurnFailed($envelope, $data),
            'turn.steered' => $this->decodeTurnSteered($envelope, $data),
            'agent.started' => $this->decodeAgentStarted($envelope, $data),
            'agent.plan' => $this->decodeAgentPlan($envelope, $data),
            'agent.text.delta' => $this->decodeAgentTextDelta($envelope, $data),
            'agent.text.done' => $this->decodeAgentTextDone($envelope, $data),
            'agent.reasoning.delta' => $this->decodeAgentReasoningDelta($envelope, $data),
            'agent.reasoning.done' => $this->decodeAgentReasoningDone($envelope, $data),
            'agent.delegated' => $this->decodeAgentDelegated($envelope, $data),
            'agent.tool.start' => $this->decodeAgentToolStart($envelope, $data, $treeData),
            'agent.tool.delta' => $this->decodeAgentToolDelta($envelope, $data),
            'agent.tool.complete' => $this->decodeAgentToolComplete($envelope, $data),
            'agent.tool.request' => $this->decodeAgentToolRequest($envelope, $data, $treeData),
            'agent.tool.result' => $this->decodeAgentToolResult($envelope, $data),
            'agent.escalate' => $this->decodeAgentEscalate($envelope, $data, $treeData),
            'agent.escalation.result' => $this->decodeAgentEscalationResult($envelope, $data),
            'agent.artifact' => $this->decodeAgentArtifact($envelope, $data),
            'agent.context.compacting' => $this->decodeAgentContextCompacting($envelope, $data),
            'agent.context.compacted' => $this->decodeAgentContextCompacted($envelope, $data),
            'agent.done' => $this->decodeAgentDone($envelope, $data),
            'agent.failed' => $this->decodeAgentFailed($envelope, $data),
            'approval.request' => $this->decodeApprovalRequest($envelope, $data, $treeData),
            'approval.response' => $this->decodeApprovalResponse($envelope, $data),
            'approval.expire' => $this->decodeApprovalExpire($envelope, $data),
            default => new UnknownEvent($envelope, new JsonObject($data)),
        };

        if (! $event instanceof UnknownEvent && $envelope->turnId === null) {
            return new ProtocolViolationEvent($envelope, ['Known replayable events require a non-null turn_id.'], $data);
        }

        return $event;
    }

    /**
     * Decodes the wire payload once as a JSON object tree (stdClass for
     * objects, arrays for lists) so empty containers keep their JSON
     * semantics.
     */
    private function decodeObject(string $json): \stdClass
    {
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ProtocolViolationException('Malformed ATP event JSON: '.$e->getMessage(), previous: $e);
        }

        if (! is_object($decoded)) {
            throw new ProtocolViolationException(sprintf('ATP event must be a JSON object, got %s.', get_debug_type($decoded)));
        }

        /** @var \stdClass $decoded */
        return $decoded;
    }

    /**
     * Converts the decoded JSON object tree to the associative shape
     * json_decode(..., true) produces, so every existing reader keeps
     * working unchanged.
     */
    private static function assoc(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $result = [];

            foreach (get_object_vars($value) as $key => $item) {
                $result[$key] = self::assoc($item);
            }

            return $result;
        }

        if (is_array($value)) {
            $items = [];

            foreach ($value as $item) {
                $items[] = self::assoc($item);
            }

            return $items;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function assertProtocolVersion(array $envelope): void
    {
        $v = $envelope['v'] ?? null;

        if (! is_int($v) || $v !== 2) {
            throw new ProtocolVersionException(sprintf(
                'Unsupported ATP protocol version %s; this SDK implements v2.',
                is_int($v) ? (string) $v : get_debug_type($v),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function buildEnvelope(array $envelope): EventEnvelope
    {
        $problems = [];

        $id = $this->requiredString($envelope, 'id', $problems);
        $seq = $this->requiredInt($envelope, 'seq', $problems);
        $ts = $this->requiredString($envelope, 'ts', $problems);
        $chatId = $this->requiredString($envelope, 'chat_id', $problems);
        $type = $this->requiredString($envelope, 'type', $problems);
        $data = $this->requiredObject($envelope, 'data', $problems);
        $turnId = $this->nullableString($envelope, 'turn_id', $problems);
        $agent = $this->nullableAgent($envelope, $problems);

        if ($problems !== []) {
            throw new ProtocolViolationException('Invalid ATP event envelope: '.implode('; ', $problems).'.');
        }

        return new EventEnvelope(
            v: 2,
            id: $id,
            seq: $seq,
            ts: $ts,
            chatId: $chatId,
            turnId: $turnId,
            agent: $agent,
            type: $type,
            extra: Payload::extra($envelope, ['v', 'id', 'seq', 'ts', 'chat_id', 'turn_id', 'agent', 'type', 'data']),
            raw: $envelope,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodePing(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];

        if ($envelope->seq !== 0) {
            $violations[] = sprintf('ping must use seq 0, got %d.', $envelope->seq);
        }

        if ($envelope->turnId !== null) {
            $violations[] = 'ping must have a null turn_id.';
        }

        if ($envelope->agent !== null) {
            $violations[] = 'ping must have a null agent.';
        }

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        return new PingEvent($envelope, Payload::extra($data, []));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeTurnStarted(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $turnId = Payload::string($data, 'turn_id', $violations, 'turn.started');
        $input = Payload::string($data, 'input', $violations, 'turn.started');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($turnId !== null && $input !== null);

        return new TurnStartedEvent($envelope, $turnId, $input, Payload::extra($data, ['turn_id', 'input']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeTurnCompleted(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $turnId = Payload::string($data, 'turn_id', $violations, 'turn.completed');
        $usage = Payload::usage($data, 'usage', $violations, 'turn.completed');
        $cost = Payload::requiredNullableFloat($data, 'cost', $violations, 'turn.completed');
        $chatUsage = Payload::usage($data, 'chat_usage', $violations, 'turn.completed');
        $chatCost = Payload::requiredNullableFloat($data, 'chat_cost', $violations, 'turn.completed');
        $billing = Payload::billing($data, $violations, 'turn.completed');
        $usageCost = Payload::usageCost($data, $violations, 'turn.completed');
        $finishReason = Payload::string($data, 'finish_reason', $violations, 'turn.completed');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($turnId !== null && $usage !== null && $chatUsage !== null && $finishReason !== null);

        return new TurnCompletedEvent($envelope, $turnId, $usage, $cost, $chatUsage, $chatCost, $finishReason, Payload::extra($data, ['turn_id', 'usage', 'cost', 'chat_usage', 'chat_cost', 'finish_reason', 'billing', 'usage_cost']), $billing, $usageCost);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeTurnCancelled(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $turnId = Payload::string($data, 'turn_id', $violations, 'turn.cancelled');
        $reason = Payload::string($data, 'reason', $violations, 'turn.cancelled');
        $usage = Payload::usage($data, 'usage', $violations, 'turn.cancelled');
        $cost = Payload::requiredNullableFloat($data, 'cost', $violations, 'turn.cancelled');
        $chatUsage = Payload::usage($data, 'chat_usage', $violations, 'turn.cancelled');
        $chatCost = Payload::requiredNullableFloat($data, 'chat_cost', $violations, 'turn.cancelled');
        $billing = Payload::billing($data, $violations, 'turn.cancelled');
        $usageCost = Payload::usageCost($data, $violations, 'turn.cancelled');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($turnId !== null && $reason !== null && $usage !== null && $chatUsage !== null);

        return new TurnCancelledEvent($envelope, $turnId, $reason, $usage, $cost, $chatUsage, $chatCost, Payload::extra($data, ['turn_id', 'reason', 'usage', 'cost', 'chat_usage', 'chat_cost', 'billing', 'usage_cost']), $billing, $usageCost);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeTurnFailed(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $turnId = Payload::string($data, 'turn_id', $violations, 'turn.failed');
        $code = Payload::string($data, 'code', $violations, 'turn.failed');
        $message = Payload::string($data, 'message', $violations, 'turn.failed');
        $usage = Payload::usage($data, 'usage', $violations, 'turn.failed');
        $cost = Payload::requiredNullableFloat($data, 'cost', $violations, 'turn.failed');
        $chatUsage = Payload::usage($data, 'chat_usage', $violations, 'turn.failed');
        $chatCost = Payload::requiredNullableFloat($data, 'chat_cost', $violations, 'turn.failed');
        $billing = Payload::billing($data, $violations, 'turn.failed');
        $usageCost = Payload::usageCost($data, $violations, 'turn.failed');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($turnId !== null && $code !== null && $message !== null && $usage !== null && $chatUsage !== null);

        return new TurnFailedEvent($envelope, $turnId, $code, $message, $usage, $cost, $chatUsage, $chatCost, Payload::extra($data, ['turn_id', 'code', 'message', 'usage', 'cost', 'chat_usage', 'chat_cost', 'billing', 'usage_cost']), $billing, $usageCost);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeTurnSteered(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $turnId = Payload::string($data, 'turn_id', $violations, 'turn.steered');
        $text = Payload::string($data, 'text', $violations, 'turn.steered');
        $targetAgentId = Payload::nullableString($data, 'target_agent_id', $violations, 'turn.steered');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($turnId !== null && $text !== null);

        return new TurnSteeredEvent($envelope, $turnId, $text, $targetAgentId, Payload::extra($data, ['turn_id', 'text', 'target_agent_id']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentStarted(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $name = Payload::string($data, 'name', $violations, 'agent.started');
        $role = Payload::string($data, 'role', $violations, 'agent.started');
        $model = Payload::string($data, 'model', $violations, 'agent.started');
        $parentId = Payload::requiredNullableString($data, 'parent_id', $violations, 'agent.started');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($name !== null && $role !== null && $model !== null);

        return new AgentStartedEvent($envelope, $name, $role, $model, $parentId, Payload::extra($data, ['name', 'role', 'model', 'parent_id']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentPlan(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $plan = Payload::string($data, 'plan', $violations, 'agent.plan');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($plan !== null);

        return new AgentPlanEvent($envelope, $plan, Payload::extra($data, ['plan']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentTextDelta(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $delta = Payload::string($data, 'delta', $violations, 'agent.text.delta');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($delta !== null);

        return new AgentTextDeltaEvent($envelope, $delta, Payload::extra($data, ['delta']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentTextDone(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $text = Payload::string($data, 'text', $violations, 'agent.text.done');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($text !== null);

        return new AgentTextDoneEvent($envelope, $text, Payload::extra($data, ['text']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentReasoningDelta(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $delta = Payload::string($data, 'delta', $violations, 'agent.reasoning.delta');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($delta !== null);

        return new AgentReasoningDeltaEvent($envelope, $delta, Payload::extra($data, ['delta']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentReasoningDone(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $text = Payload::string($data, 'text', $violations, 'agent.reasoning.done');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($text !== null);

        return new AgentReasoningDoneEvent($envelope, $text, Payload::extra($data, ['text']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentDelegated(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $childInstanceId = Payload::string($data, 'child_instance_id', $violations, 'agent.delegated');
        $childName = Payload::string($data, 'child_name', $violations, 'agent.delegated');
        $mode = Payload::string($data, 'mode', $violations, 'agent.delegated');
        $title = Payload::string($data, 'title', $violations, 'agent.delegated');
        $task = Payload::string($data, 'task', $violations, 'agent.delegated');

        if ($mode !== null && $mode !== 'foreground' && $mode !== 'background') {
            $violations[] = sprintf('agent.delegated: payload field "mode" must be "foreground" or "background", got "%s".', $mode);
        }

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($childInstanceId !== null && $childName !== null && $mode !== null && $title !== null && $task !== null);

        return new AgentDelegatedEvent($envelope, $childInstanceId, $childName, $mode, $title, $task, Payload::extra($data, ['child_instance_id', 'child_name', 'mode', 'title', 'task']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentToolStart(EventEnvelope $envelope, array $data, \stdClass $treeData): AtpEvent
    {
        $violations = [];
        $callId = Payload::string($data, 'call_id', $violations, 'agent.tool.start');
        $toolName = Payload::string($data, 'tool_name', $violations, 'agent.tool.start');
        $kind = Payload::toolKind($data, 'kind', $violations, 'agent.tool.start');
        $args = Payload::treeObject($treeData, 'args', $violations, 'agent.tool.start');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($callId !== null && $toolName !== null && $kind !== null && $args !== null);

        return new AgentToolStartEvent($envelope, $callId, $toolName, $kind, $args, Payload::extra($data, ['call_id', 'tool_name', 'kind', 'args']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentToolDelta(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $callId = Payload::string($data, 'call_id', $violations, 'agent.tool.delta');
        $delta = Payload::string($data, 'delta', $violations, 'agent.tool.delta');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($callId !== null && $delta !== null);

        return new AgentToolDeltaEvent($envelope, $callId, $delta, Payload::extra($data, ['call_id', 'delta']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentToolComplete(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $callId = Payload::string($data, 'call_id', $violations, 'agent.tool.complete');
        $result = Payload::result($data, 'result', $violations, 'agent.tool.complete');
        $isError = Payload::bool($data, 'is_error', $violations, 'agent.tool.complete');
        $usage = Payload::usage($data, 'usage', $violations, 'agent.tool.complete');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($callId !== null && $isError !== null && $usage !== null);

        return new AgentToolCompleteEvent($envelope, $callId, $result, $isError, $usage, Payload::extra($data, ['call_id', 'result', 'is_error', 'usage']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentToolRequest(EventEnvelope $envelope, array $data, \stdClass $treeData): AtpEvent
    {
        $violations = [];
        $callId = Payload::string($data, 'call_id', $violations, 'agent.tool.request');
        $toolName = Payload::string($data, 'tool_name', $violations, 'agent.tool.request');
        $kind = Payload::toolKind($data, 'kind', $violations, 'agent.tool.request');
        $signature = Payload::signature($data, 'signature', $violations, 'agent.tool.request');
        $args = Payload::requestArgs($treeData, $violations, 'agent.tool.request');

        if ($kind !== null && $kind !== ToolKind::Client) {
            $violations[] = sprintf('agent.tool.request: payload field "kind" must be "client", got "%s".', $kind->value);
        }

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($callId !== null && $toolName !== null && $kind !== null && $args !== null && $signature !== null);

        return new AgentToolRequestEvent($envelope, $callId, $toolName, $kind, $args, $signature, Payload::extra($data, ['call_id', 'tool_name', 'kind', 'args', 'signature']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentToolResult(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $callId = Payload::string($data, 'call_id', $violations, 'agent.tool.result');
        $output = Payload::string($data, 'output', $violations, 'agent.tool.result');
        $isError = Payload::bool($data, 'is_error', $violations, 'agent.tool.result');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($callId !== null && $output !== null && $isError !== null);

        return new AgentToolResultEvent($envelope, $callId, $output, $isError, Payload::extra($data, ['call_id', 'output', 'is_error']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentEscalate(EventEnvelope $envelope, array $data, \stdClass $treeData): AtpEvent
    {
        $violations = [];
        $escalationId = Payload::string($data, 'escalation_id', $violations, 'agent.escalate');
        $agentId = Payload::string($data, 'agent_id', $violations, 'agent.escalate');
        $reason = Payload::string($data, 'reason', $violations, 'agent.escalate');
        $toolName = Payload::string($data, 'tool_name', $violations, 'agent.escalate');
        $args = Payload::treeObject($treeData, 'args', $violations, 'agent.escalate');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($escalationId !== null && $agentId !== null && $reason !== null && $toolName !== null && $args !== null);

        return new AgentEscalateEvent($envelope, $escalationId, $agentId, $reason, $toolName, $args, Payload::extra($data, ['escalation_id', 'agent_id', 'reason', 'tool_name', 'args']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentEscalationResult(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $escalationId = Payload::string($data, 'escalation_id', $violations, 'agent.escalation.result');
        $approve = Payload::bool($data, 'approve', $violations, 'agent.escalation.result');
        $reason = Payload::string($data, 'reason', $violations, 'agent.escalation.result');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($escalationId !== null && $approve !== null && $reason !== null);

        return new AgentEscalationResultEvent($envelope, $escalationId, $approve, $reason, Payload::extra($data, ['escalation_id', 'approve', 'reason']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentArtifact(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $artifactId = Payload::string($data, 'artifact_id', $violations, 'agent.artifact');
        $name = Payload::string($data, 'name', $violations, 'agent.artifact');
        $mediaType = Payload::string($data, 'media_type', $violations, 'agent.artifact');
        $size = Payload::int($data, 'size', $violations, 'agent.artifact');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($artifactId !== null && $name !== null && $mediaType !== null && $size !== null);

        return new AgentArtifactEvent($envelope, $artifactId, $name, $mediaType, $size, Payload::extra($data, ['artifact_id', 'name', 'media_type', 'size']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentContextCompacting(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $type = 'agent.context.compacting';
        $turnId = Payload::nullableString($data, 'turn_id', $violations, $type);
        $reason = Payload::nullableString($data, 'reason', $violations, $type);
        $contextTokens = Payload::nullableInt($data, 'context_tokens', $violations, $type);
        $thresholdTokens = Payload::nullableInt($data, 'threshold_tokens', $violations, $type);
        $windowTokens = Payload::nullableInt($data, 'window_tokens', $violations, $type);
        $threshold = Payload::nullableFloat($data, 'threshold', $violations, $type);

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        return new AgentContextCompactingEvent(
            $envelope,
            Payload::extra($data, ['turn_id', 'reason', 'context_tokens', 'threshold_tokens', 'window_tokens', 'threshold']),
            $turnId, $reason, $contextTokens, $thresholdTokens, $windowTokens, $threshold,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentContextCompacted(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $newContext = Payload::string($data, 'new_context', $violations, 'agent.context.compacted');
        $turnId = Payload::nullableString($data, 'turn_id', $violations, 'agent.context.compacted');
        $contextTokens = Payload::nullableInt($data, 'context_tokens', $violations, 'agent.context.compacted');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($newContext !== null);

        return new AgentContextCompactedEvent($envelope, $newContext, Payload::extra($data, ['new_context', 'turn_id', 'context_tokens']), $turnId, $contextTokens);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentDone(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $status = Payload::string($data, 'status', $violations, 'agent.done');
        $summary = Payload::string($data, 'summary', $violations, 'agent.done');
        $usage = Payload::usage($data, 'usage', $violations, 'agent.done');
        $durationMs = Payload::int($data, 'duration_ms', $violations, 'agent.done');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($status !== null && $summary !== null && $usage !== null && $durationMs !== null);

        return new AgentDoneEvent($envelope, $status, $summary, $usage, $durationMs, Payload::extra($data, ['status', 'summary', 'usage', 'duration_ms']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeAgentFailed(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $code = Payload::string($data, 'code', $violations, 'agent.failed');
        $message = Payload::string($data, 'message', $violations, 'agent.failed');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($code !== null && $message !== null);

        return new AgentFailedEvent($envelope, $code, $message, Payload::extra($data, ['code', 'message']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeApprovalRequest(EventEnvelope $envelope, array $data, \stdClass $treeData): AtpEvent
    {
        $violations = [];
        $approvalId = Payload::string($data, 'approval_id', $violations, 'approval.request');
        $originAgentId = Payload::string($data, 'origin_agent_id', $violations, 'approval.request');
        $toolName = Payload::string($data, 'tool_name', $violations, 'approval.request');
        $args = Payload::treeObject($treeData, 'args', $violations, 'approval.request');
        $reason = Payload::string($data, 'reason', $violations, 'approval.request');
        $deadline = Payload::string($data, 'deadline', $violations, 'approval.request');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($approvalId !== null && $originAgentId !== null && $toolName !== null && $args !== null && $reason !== null && $deadline !== null);

        return new ApprovalRequestEvent($envelope, $approvalId, $originAgentId, $toolName, $args, $reason, $deadline, Payload::extra($data, ['approval_id', 'origin_agent_id', 'tool_name', 'args', 'reason', 'deadline']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeApprovalResponse(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $approvalId = Payload::string($data, 'approval_id', $violations, 'approval.response');
        $approve = Payload::bool($data, 'approve', $violations, 'approval.response');
        $reason = Payload::string($data, 'reason', $violations, 'approval.response');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($approvalId !== null && $approve !== null && $reason !== null);

        return new ApprovalResponseEvent($envelope, $approvalId, $approve, $reason, Payload::extra($data, ['approval_id', 'approve', 'reason']));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decodeApprovalExpire(EventEnvelope $envelope, array $data): AtpEvent
    {
        $violations = [];
        $approvalId = Payload::string($data, 'approval_id', $violations, 'approval.expire');

        if ($violations !== []) {
            return new ProtocolViolationEvent($envelope, $violations, $data);
        }

        assert($approvalId !== null);

        return new ApprovalExpireEvent($envelope, $approvalId, Payload::extra($data, ['approval_id']));
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  list<string>  $problems
     */
    private function requiredString(array $envelope, string $key, array &$problems): string
    {
        $value = Payload::string($envelope, $key, $problems, 'envelope');

        if ($value === null) {
            return '';
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  list<string>  $problems
     */
    private function requiredInt(array $envelope, string $key, array &$problems): int
    {
        $value = Payload::int($envelope, $key, $problems, 'envelope');

        if ($value === null) {
            return 0;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  list<string>  $problems
     * @return array<string, mixed>|null
     */
    private function requiredObject(array $envelope, string $key, array &$problems): ?array
    {
        return Payload::object($envelope, $key, $problems, 'envelope');
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  list<string>  $problems
     */
    private function nullableString(array $envelope, string $key, array &$problems): ?string
    {
        if (! array_key_exists($key, $envelope)) {
            $problems[] = sprintf('envelope: field "%s" is required.', $key);

            return null;
        }

        return Payload::nullableString($envelope, $key, $problems, 'envelope');
    }

    /**
     * @param  array<string, mixed>  $envelope
     * @param  list<string>  $problems
     */
    private function nullableAgent(array $envelope, array &$problems): ?AgentInstance
    {
        if (! array_key_exists('agent', $envelope)) {
            $problems[] = 'envelope: field "agent" is required.';

            return null;
        }

        if ($envelope['agent'] === null) {
            return null;
        }

        $value = Payload::object($envelope, 'agent', $problems, 'envelope');

        if ($value === null) {
            return null;
        }

        $instanceId = Payload::string($value, 'instance_id', $problems, 'envelope.agent');
        $name = Payload::string($value, 'name', $problems, 'envelope.agent');
        $role = Payload::string($value, 'role', $problems, 'envelope.agent');
        $parentId = Payload::requiredNullableString($value, 'parent_id', $problems, 'envelope.agent');
        $model = Payload::nullableString($value, 'model', $problems, 'envelope.agent');

        if ($instanceId === null || $name === null || $role === null) {
            return null;
        }

        return new AgentInstance(
            instanceId: $instanceId,
            name: $name,
            role: $role,
            parentId: $parentId,
            model: $model,
            extra: Payload::extra($value, ['instance_id', 'name', 'role', 'parent_id', 'model']),
        );
    }
}
