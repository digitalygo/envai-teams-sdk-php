<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Streaming\Events;

use Digitalygo\Envai\Exceptions\ProtocolVersionException;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Events\AgentArtifactEvent;
use Digitalygo\Envai\Streaming\Events\AgentContextCompactedEvent;
use Digitalygo\Envai\Streaming\Events\AgentContextCompactingEvent;
use Digitalygo\Envai\Streaming\Events\AgentDelegatedEvent;
use Digitalygo\Envai\Streaming\Events\AgentDoneEvent;
use Digitalygo\Envai\Streaming\Events\AgentEscalateEvent;
use Digitalygo\Envai\Streaming\Events\AgentEscalationResultEvent;
use Digitalygo\Envai\Streaming\Events\AgentFailedEvent;
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
use Digitalygo\Envai\Streaming\Events\PingEvent;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Streaming\Events\ToolKind;
use Digitalygo\Envai\Streaming\Events\TurnCancelledEvent;
use Digitalygo\Envai\Streaming\Events\TurnCompletedEvent;
use Digitalygo\Envai\Streaming\Events\TurnFailedEvent;
use Digitalygo\Envai\Streaming\Events\TurnStartedEvent;
use Digitalygo\Envai\Streaming\Events\TurnSteeredEvent;
use Digitalygo\Envai\Streaming\Events\UnknownEvent;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AtpEventDecoderTest extends TestCase
{
    private AtpEventDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new AtpEventDecoder;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function json(array $envelope): string
    {
        return json_encode($envelope, JSON_THROW_ON_ERROR);
    }

    public function test_malformed_json_throws_protocol_violation(): void
    {
        $this->expectException(ProtocolViolationException::class);

        $this->decoder->decode('{not json');
    }

    public function test_non_object_json_throws_protocol_violation(): void
    {
        $this->expectException(ProtocolViolationException::class);

        $this->decoder->decode('[1, 2, 3]');
    }

    public function test_scalar_json_throws_protocol_violation(): void
    {
        $this->expectException(ProtocolViolationException::class);

        $this->decoder->decode('"just a string"');
    }

    public function test_v1_throws_protocol_version_exception(): void
    {
        $envelope = Fixtures::sample('turn.started');
        $envelope['v'] = 1;

        $this->expectException(ProtocolVersionException::class);

        $this->decoder->decode($this->json($envelope));
    }

    public function test_missing_v_throws_protocol_version_exception(): void
    {
        $envelope = Fixtures::sample('turn.started');
        unset($envelope['v']);

        $this->expectException(ProtocolVersionException::class);

        $this->decoder->decode($this->json($envelope));
    }

    public function test_string_v_throws_protocol_version_exception(): void
    {
        $envelope = Fixtures::sample('turn.started');
        $envelope['v'] = '2';

        $this->expectException(ProtocolVersionException::class);

        $this->decoder->decode($this->json($envelope));
    }

    public function test_version_is_checked_before_payload_interpretation(): void
    {
        $envelope = Fixtures::sample('turn.started');
        $envelope['v'] = 1;
        $envelope['data'] = 'not an object';

        $this->expectException(ProtocolVersionException::class);

        $this->decoder->decode($this->json($envelope));
    }

    public function test_missing_envelope_id_throws_protocol_violation(): void
    {
        $envelope = Fixtures::sample('turn.started');
        unset($envelope['id']);

        $this->expectException(ProtocolViolationException::class);

        $this->decoder->decode($this->json($envelope));
    }

    public function test_missing_envelope_data_throws_protocol_violation(): void
    {
        $envelope = Fixtures::sample('turn.started');
        unset($envelope['data']);

        $this->expectException(ProtocolViolationException::class);

        $this->decoder->decode($this->json($envelope));
    }

    public function test_envelope_data_as_list_throws_protocol_violation(): void
    {
        $envelope = Fixtures::sample('turn.started');
        $envelope['data'] = [1, 2, 3];

        $this->expectException(ProtocolViolationException::class);

        $this->decoder->decode($this->json($envelope));
    }

    public function test_envelope_missing_turn_id_throws_protocol_violation(): void
    {
        $envelope = Fixtures::sample('turn.started');
        unset($envelope['turn_id']);

        $this->expectException(ProtocolViolationException::class);

        $this->decoder->decode($this->json($envelope));
    }

    public function test_envelope_missing_agent_throws_protocol_violation(): void
    {
        $envelope = Fixtures::sample('turn.started');
        unset($envelope['agent']);

        $this->expectException(ProtocolViolationException::class);

        $this->decoder->decode($this->json($envelope));
    }

    public function test_valid_ping_decodes_as_control_event(): void
    {
        $event = $this->decoder->decode($this->json(Fixtures::ping()));

        $this->assertInstanceOf(PingEvent::class, $event);
        $this->assertSame('ping', $event->type());
        $this->assertSame(0, $event->envelope->seq);
        $this->assertNull($event->envelope->turnId);
        $this->assertNull($event->envelope->agent);
        $this->assertSame(Fixtures::CHAT_ID, $event->envelope->chatId);
    }

    public function test_ping_with_nonzero_seq_is_a_protocol_violation_event(): void
    {
        $envelope = Fixtures::ping();
        $envelope['seq'] = 1;

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertSame(['ping must use seq 0, got 1.'], $event->violations);
    }

    public function test_ping_with_turn_id_is_a_protocol_violation_event(): void
    {
        $envelope = Fixtures::ping();
        $envelope['turn_id'] = Fixtures::TURN_ID;

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertStringContainsString('null turn_id', $event->violations[0]);
    }

    public function test_ping_with_agent_is_a_protocol_violation_event(): void
    {
        $envelope = Fixtures::ping();
        $envelope['agent'] = Fixtures::orchestrator();

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertStringContainsString('null agent', $event->violations[0]);
    }

    public function test_unknown_type_is_delivered_as_unknown_event_with_data_untouched(): void
    {
        $data = ['future' => ['nested' => [1, 2, 3]], 'z' => 'keep-order'];
        $envelope = Fixtures::envelope(5, 'evt_01j5abcdefghjkmnpqrstuvwxy5', '2026-08-17T14:00:00Z', 'future.feature', $data, null);

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(UnknownEvent::class, $event);
        $this->assertSame('unknown', $event->type());
        $this->assertSame('future.feature', $event->envelope->type);
        $this->assertSame(['future' => ['nested' => [1, 2, 3]], 'z' => 'keep-order'], $event->data->toArray());
        $this->assertSame(['future' => ['nested' => [1, 2, 3]], 'z' => 'keep-order'], $event->extra);
    }

    /**
     * @return iterable<string, array{class-string<AtpEvent>, string}>
     */
    public static function knownTypesProvider(): iterable
    {
        yield 'ping' => [PingEvent::class, 'ping'];
        yield 'turn.started' => [TurnStartedEvent::class, 'turn.started'];
        yield 'turn.completed' => [TurnCompletedEvent::class, 'turn.completed'];
        yield 'turn.cancelled' => [TurnCancelledEvent::class, 'turn.cancelled'];
        yield 'turn.failed' => [TurnFailedEvent::class, 'turn.failed'];
        yield 'turn.steered' => [TurnSteeredEvent::class, 'turn.steered'];
        yield 'agent.started' => [AgentStartedEvent::class, 'agent.started'];
        yield 'agent.plan' => [AgentPlanEvent::class, 'agent.plan'];
        yield 'agent.text.delta' => [AgentTextDeltaEvent::class, 'agent.text.delta'];
        yield 'agent.text.done' => [AgentTextDoneEvent::class, 'agent.text.done'];
        yield 'agent.reasoning.delta' => [AgentReasoningDeltaEvent::class, 'agent.reasoning.delta'];
        yield 'agent.reasoning.done' => [AgentReasoningDoneEvent::class, 'agent.reasoning.done'];
        yield 'agent.delegated' => [AgentDelegatedEvent::class, 'agent.delegated'];
        yield 'agent.tool.start' => [AgentToolStartEvent::class, 'agent.tool.start'];
        yield 'agent.tool.delta' => [AgentToolDeltaEvent::class, 'agent.tool.delta'];
        yield 'agent.tool.complete' => [AgentToolCompleteEvent::class, 'agent.tool.complete'];
        yield 'agent.tool.request' => [AgentToolRequestEvent::class, 'agent.tool.request'];
        yield 'agent.tool.result' => [AgentToolResultEvent::class, 'agent.tool.result'];
        yield 'agent.escalate' => [AgentEscalateEvent::class, 'agent.escalate'];
        yield 'agent.escalation.result' => [AgentEscalationResultEvent::class, 'agent.escalation.result'];
        yield 'agent.artifact' => [AgentArtifactEvent::class, 'agent.artifact'];
        yield 'agent.context.compacting' => [AgentContextCompactingEvent::class, 'agent.context.compacting'];
        yield 'agent.context.compacted' => [AgentContextCompactedEvent::class, 'agent.context.compacted'];
        yield 'agent.done' => [AgentDoneEvent::class, 'agent.done'];
        yield 'agent.failed' => [AgentFailedEvent::class, 'agent.failed'];
        yield 'approval.request' => [ApprovalRequestEvent::class, 'approval.request'];
        yield 'approval.response' => [ApprovalResponseEvent::class, 'approval.response'];
        yield 'approval.expire' => [ApprovalExpireEvent::class, 'approval.expire'];
    }

    /**
     * @param  class-string<AtpEvent>  $expected
     */
    #[DataProvider('knownTypesProvider')]
    public function test_every_known_type_decodes_to_its_typed_class(string $expected, string $type): void
    {
        $event = $this->decoder->decode($this->json(Fixtures::sample($type)));

        $this->assertInstanceOf($expected, $event);
        $this->assertSame($type, $event->envelope->type);
        $this->assertSame($type, $event->type());
    }

    public function test_turn_started_payload_is_typed(): void
    {
        $event = $this->decoder->decode($this->json(Fixtures::sample('turn.started')));

        $this->assertInstanceOf(TurnStartedEvent::class, $event);
        $this->assertSame(Fixtures::TURN_ID, $event->turnId);
        $this->assertSame('Ciao', $event->input);
    }

    public function test_missing_required_payload_field_is_a_violation_event(): void
    {
        $envelope = Fixtures::sample('turn.started');
        unset($envelope['data']['input']);

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertSame(['turn.started: payload field "input" is required.'], $event->violations);
        $this->assertSame(['turn_id' => Fixtures::TURN_ID], $event->data);
    }

    public function test_wrong_typed_payload_field_is_a_violation_event(): void
    {
        $envelope = Fixtures::sample('turn.started');
        $envelope['data']['input'] = 42;

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertSame(['turn.started: payload field "input" must be a string, got int.'], $event->violations);
    }

    public function test_all_payload_violations_are_collected(): void
    {
        $envelope = Fixtures::sample('turn.completed');
        unset($envelope['data']['cost'], $envelope['data']['chat_cost'], $envelope['data']['finish_reason']);

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertCount(3, $event->violations);
        $this->assertStringContainsString('"cost"', $event->violations[0]);
        $this->assertStringContainsString('"chat_cost"', $event->violations[1]);
        $this->assertStringContainsString('"finish_reason"', $event->violations[2]);
    }

    public function test_incomplete_usage_cannot_omit_required_cost_in_either_version(): void
    {
        foreach ([2, 3] as $version) {
            $envelope = Fixtures::sample('agent.done');
            $envelope['v'] = $version;
            unset($envelope['data']['usage']['cost_usd']);
            $event = $this->decoder->decode($this->json($envelope));
            $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
            $this->assertStringContainsString($version === 2 ? 'immutable ATP v2 schema' : 'cost_usd', $event->violations[0]);
        }
    }

    public function test_empty_usage_turns_a_terminal_into_a_violation_event(): void
    {
        $envelope = Fixtures::sample('turn.failed');
        $envelope['data']['usage'] = [];
        $envelope['data']['cost'] = 0.0;
        $envelope['data']['chat_usage'] = [];
        $envelope['data']['chat_cost'] = 0.0;

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertSame('turn.failed', $event->envelope->type);
        $this->assertStringContainsString('usage', $event->violations[0]);
        $this->assertStringContainsString('input_tokens', $event->violations[0]);
    }

    public function test_agent_instance_is_parsed_with_optional_model(): void
    {
        $event = $this->decoder->decode($this->json(Fixtures::sample('agent.done')));

        $this->assertInstanceOf(AgentDoneEvent::class, $event);
        $agent = $event->envelope->agent;
        $this->assertNotNull($agent);
        $this->assertSame(Fixtures::ORCHESTRATOR_ID, $agent->instanceId);
        $this->assertSame('orchestrator', $agent->name);
        $this->assertSame('orchestrator', $agent->role);
        $this->assertNull($agent->parentId);
        $this->assertSame('anthropic/claude-sonnet-4-5', $agent->model);
    }

    public function test_agent_started_with_null_parent_id_decodes(): void
    {
        $event = $this->decoder->decode($this->json(Fixtures::sample('agent.started')));

        $this->assertInstanceOf(AgentStartedEvent::class, $event);
        $this->assertNull($event->parentId);
    }

    public function test_tool_request_without_args_decodes_with_empty_object_args(): void
    {
        // The alpha gateway omits the `args` field entirely when the LLM
        // passes no arguments; it must decode to an empty object (`{}`),
        // never a list (`[]`).
        $envelope = Fixtures::sample('agent.tool.request');
        unset($envelope['data']['args']);

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(AgentToolRequestEvent::class, $event);
        $this->assertInstanceOf(JsonObject::class, $event->args);
        $this->assertTrue($event->args->isEmpty());
        $this->assertSame('{}', CanonicalJsonSerializer::serialize($event->args));
    }

    public function test_tool_request_with_explicit_empty_object_args_keeps_object_semantics(): void
    {
        $envelope = Fixtures::sample('agent.tool.request');
        $envelope['data']['args'] = new \stdClass;

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(AgentToolRequestEvent::class, $event);
        $this->assertTrue($event->args->isEmpty());
        $this->assertSame('{}', CanonicalJsonSerializer::serialize($event->args));
    }

    public function test_tool_request_args_preserve_nested_object_and_list_semantics(): void
    {
        $envelope = Fixtures::sample('agent.tool.request');
        $envelope['data']['args'] = ['a' => new \stdClass, 'b' => [1, 2], 'c' => ['d' => new \stdClass]];

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(AgentToolRequestEvent::class, $event);
        $this->assertSame('{"a":{},"b":[1,2],"c":{"d":{}}}', CanonicalJsonSerializer::serialize($event->args));
        $this->assertInstanceOf(JsonObject::class, $event->args->get('a'));
        $this->assertTrue($event->args->get('a')->isEmpty());
        $this->assertInstanceOf(JsonArray::class, $event->args->get('b'));
        $this->assertSame([1, 2], $event->args->get('b')->toArray());
        $this->assertInstanceOf(JsonObject::class, $event->args->get('c'));
        $this->assertInstanceOf(JsonObject::class, $event->args->get('c')->get('d'));
    }

    public function test_tool_request_args_as_list_is_a_violation_event(): void
    {
        $envelope = Fixtures::sample('agent.tool.request');
        $envelope['data']['args'] = [1, 2];

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertStringContainsString('must be an object', $event->violations[0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function argsBearingEventsProvider(): iterable
    {
        yield 'agent.tool.start' => ['agent.tool.start'];
        yield 'agent.escalate' => ['agent.escalate'];
        yield 'approval.request' => ['approval.request'];
    }

    #[DataProvider('argsBearingEventsProvider')]
    public function test_args_bearing_events_preserve_nested_object_semantics(string $type): void
    {
        $envelope = Fixtures::sample($type);
        $envelope['data']['args'] = ['a' => new \stdClass];

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertSame('{"a":{}}', CanonicalJsonSerializer::serialize($event->args));
    }

    public function test_client_tool_request_preserves_args_raw_and_signature(): void
    {
        $event = $this->decoder->decode($this->json(Fixtures::sample('agent.tool.request')));

        $this->assertInstanceOf(AgentToolRequestEvent::class, $event);
        $this->assertSame('tc_01j5abcdefghjkmnpqrstuvwxyz', $event->callId);
        $this->assertSame('lookup_order', $event->toolName);
        $this->assertSame(ToolKind::Client, $event->kind);
        $this->assertInstanceOf(JsonObject::class, $event->args);
        $this->assertSame(['order_id' => 'ord_123'], $event->args->toArray());
        $this->assertSame('EdDSA', $event->signature->alg);
        $this->assertSame('sig_01j5abcdefghjkmnpqrstuvwxy1', $event->signature->kid);
        $this->assertSame('c2lnbmF0dXJl', $event->signature->sig);
    }

    public function test_tool_request_with_non_client_kind_is_a_violation_event(): void
    {
        $envelope = Fixtures::sample('agent.tool.request');
        $envelope['data']['kind'] = 'server';

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertStringContainsString('"kind" must be "client"', $event->violations[0]);
    }

    public function test_tool_request_with_wrong_signature_alg_is_a_violation_event(): void
    {
        $envelope = Fixtures::sample('agent.tool.request');
        $envelope['data']['signature']['alg'] = 'RS256';

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertStringContainsString('signature.alg', $event->violations[0]);
    }

    public function test_invalid_tool_kind_is_a_violation_event(): void
    {
        $envelope = Fixtures::sample('agent.tool.start');
        $envelope['data']['kind'] = 'quantum';

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertStringContainsString('"kind"', $event->violations[0]);
    }

    public function test_invalid_delegation_mode_is_a_violation_event(): void
    {
        $envelope = Fixtures::sample('agent.delegated');
        $envelope['data']['mode'] = 'sideways';

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertStringContainsString('"mode"', $event->violations[0]);
    }

    public function test_tool_complete_structured_results_keep_their_shape(): void
    {
        $objectEnvelope = Fixtures::sample('agent.tool.complete');
        $objectEnvelope['data']['result'] = ['rows' => [1, 2], 'ok' => true];

        $objectEvent = $this->decoder->decode($this->json($objectEnvelope));

        $this->assertInstanceOf(AgentToolCompleteEvent::class, $objectEvent);
        $this->assertInstanceOf(JsonObject::class, $objectEvent->result);
        $this->assertSame(['rows' => [1, 2], 'ok' => true], $objectEvent->result->toArray());

        $listEnvelope = Fixtures::sample('agent.tool.complete');
        $listEnvelope['data']['result'] = [1, 2, 3];

        $listEvent = $this->decoder->decode($this->json($listEnvelope));

        $this->assertInstanceOf(AgentToolCompleteEvent::class, $listEvent);
        $this->assertInstanceOf(JsonArray::class, $listEvent->result);
        $this->assertSame([1, 2, 3], $listEvent->result->toArray());
    }

    public function test_tool_complete_null_result_is_valid(): void
    {
        $envelope = Fixtures::sample('agent.tool.complete');
        $envelope['data']['result'] = null;

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(AgentToolCompleteEvent::class, $event);
        $this->assertNull($event->result);
    }

    public function test_non_ping_event_with_seq_zero_is_a_violation_event(): void
    {
        $envelope = Fixtures::sample('turn.started');
        $envelope['seq'] = 0;

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(ProtocolViolationEvent::class, $event);
        $this->assertStringContainsString('positive seq', $event->violations[0]);
    }

    public function test_unknown_envelope_top_level_fields_go_to_extra(): void
    {
        $envelope = Fixtures::sample('turn.started');
        $envelope['epoch'] = 7;
        $envelope['trace_id'] = 'trace-123';

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(TurnStartedEvent::class, $event);
        $this->assertSame(['epoch' => 7, 'trace_id' => 'trace-123'], $event->envelope->extra);
    }

    public function test_unknown_payload_fields_go_to_event_extra(): void
    {
        $envelope = Fixtures::sample('turn.started');
        $envelope['data']['locale'] = 'it-IT';

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(TurnStartedEvent::class, $event);
        $this->assertSame(['locale' => 'it-IT'], $event->extra);
    }

    public function test_unknown_usage_fields_go_to_usage_extra(): void
    {
        $envelope = Fixtures::sample('agent.done');
        $envelope['data']['usage']['currency'] = 'USD';

        $event = $this->decoder->decode($this->json($envelope));

        $this->assertInstanceOf(AgentDoneEvent::class, $event);
        $this->assertSame(['currency' => 'USD'], $event->usage->extra);
    }

    public function test_raw_returns_the_complete_decoded_envelope(): void
    {
        $envelope = Fixtures::sample('turn.started');
        $event = $this->decoder->decode($this->json($envelope));

        $this->assertSame($envelope, $event->raw());
        $this->assertSame($envelope, $event->envelope->raw);
    }

    public function test_approval_response_carries_null_agent(): void
    {
        $event = $this->decoder->decode($this->json(Fixtures::sample('approval.response')));

        $this->assertInstanceOf(ApprovalResponseEvent::class, $event);
        $this->assertNull($event->envelope->agent);
        $this->assertTrue($event->approve);
    }

    public function test_delegated_event_payload(): void
    {
        $event = $this->decoder->decode($this->json(Fixtures::sample('agent.delegated')));

        $this->assertInstanceOf(AgentDelegatedEvent::class, $event);
        $this->assertSame(Fixtures::MEMBER_ID, $event->childInstanceId);
        $this->assertSame('researcher', $event->childName);
        $this->assertSame('foreground', $event->mode);
    }
}
