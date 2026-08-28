<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\Chat\ApprovalDecisionRequest;
use Digitalygo\Envai\Models\Chat\Chat;
use Digitalygo\Envai\Models\Chat\ChatCreateRequest;
use Digitalygo\Envai\Models\Chat\ChatForked;
use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use Digitalygo\Envai\Models\Chat\ForkChatRequest;
use Digitalygo\Envai\Models\Chat\OrchestratorContextItem;
use Digitalygo\Envai\Models\Chat\OrchestratorContextKind;
use Digitalygo\Envai\Models\Chat\RestoreChatAccepted;
use Digitalygo\Envai\Models\Chat\RestoreChatRequest;
use Digitalygo\Envai\Models\Chat\SteerRequest;
use Digitalygo\Envai\Models\Chat\ToolOutputRequest;
use Digitalygo\Envai\Models\Chat\TurnAccepted;
use PHPUnit\Framework\TestCase;

final class ChatTest extends TestCase
{
    public function test_chat_hydrates_and_preserves_extra(): void
    {
        $chat = Chat::fromArray([
            'id' => 'cht_01j5abcdefghjkmnpqrstvwxyz',
            'workspace_id' => 'wsp_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'team_id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:05:00+00:00',
            'pinned' => true,
        ]);

        $this->assertSame('cht_01j5abcdefghjkmnpqrstvwxyz', $chat->id);
        $this->assertSame('team_01j5abcdefghjkmnpqrstvwxyz', $chat->teamId);
        $this->assertSame(['pinned' => true], $chat->extra);
    }

    public function test_chat_message_accepted_hydrates(): void
    {
        $accepted = ChatMessageAccepted::fromArray([
            'status' => 'accepted',
            'turn_id' => 'cmpl_01j5abcdefghjkmnpqrstvwxyz',
            'queued_at' => '2026-08-17T14:00:00+00:00',
        ]);

        $this->assertSame('accepted', $accepted->status);
        $this->assertSame('cmpl_01j5abcdefghjkmnpqrstvwxyz', $accepted->turnId);
        $this->assertSame(['queued_at' => '2026-08-17T14:00:00+00:00'], $accepted->extra);
    }

    public function test_turn_accepted_hydrates(): void
    {
        $turn = TurnAccepted::fromArray(['turn_id' => 'cmpl_01j5abcdefghjkmnpqrstvwxyz']);

        $this->assertSame('cmpl_01j5abcdefghjkmnpqrstvwxyz', $turn->turnId);
        $this->assertSame(['turn_id' => 'cmpl_01j5abcdefghjkmnpqrstvwxyz'], $turn->toArray());
    }

    public function test_chat_forked_hydrates(): void
    {
        $forked = ChatForked::fromArray([
            'source_chat_id' => 'cht_01j5abcdefghjkmnpqrstvwxyz',
            'destination_chat_id' => 'cht_01j5abcdefghjkmnpqrstuvwxyZ',
            'snapshot_at' => '2026-08-17T14:06:00+00:00',
        ]);

        $this->assertSame('cht_01j5abcdefghjkmnpqrstvwxyz', $forked->sourceChatId);
        $this->assertSame('cht_01j5abcdefghjkmnpqrstuvwxyZ', $forked->destinationChatId);
        $this->assertSame(['snapshot_at' => '2026-08-17T14:06:00+00:00'], $forked->extra);
    }

    public function test_restore_chat_accepted_hydrates_with_null_turn_id(): void
    {
        $restored = RestoreChatAccepted::fromArray([
            'status' => 'restored',
            'turn_id' => null,
        ]);

        $this->assertSame('restored', $restored->status);
        $this->assertNull($restored->turnId);
    }

    public function test_orchestrator_context_item_coerces_enum_kind(): void
    {
        $item = OrchestratorContextItem::fromArray([
            'kind' => 'compacted',
            'content' => 'Compattazione del contesto fino al turno precedente.',
            'turn_id' => null,
            'seq' => 1,
        ]);

        $this->assertSame(OrchestratorContextKind::Compacted, $item->kind);
        $this->assertSame('compacted', $item->kind->value);
        $this->assertNull($item->turnId);
        $this->assertSame(['seq' => 1], $item->extra);
    }

    public function test_orchestrator_context_item_throws_on_unknown_kind(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('OrchestratorContextItem payload field "kind" has unknown value "user"');

        OrchestratorContextItem::fromArray(['kind' => 'user', 'content' => 'hello']);
    }

    public function test_orchestrator_context_item_round_trips_enum(): void
    {
        $payload = [
            'kind' => 'assistant',
            'turn_id' => 'cmpl_01j5abcdefghjkmnpqrstvwxyz',
            'content' => "Ecco l'analisi del traffico: 12k visitatori, +8%.",
        ];

        $item = OrchestratorContextItem::fromArray($payload);

        $this->assertSame(OrchestratorContextKind::Assistant, $item->kind);
        $this->assertEquals($payload, $item->toArray());
    }

    public function test_restore_chat_request_serializes_context_items(): void
    {
        $request = new RestoreChatRequest([
            new OrchestratorContextItem(OrchestratorContextKind::Compacted, 'Compattazione del contesto.'),
            new OrchestratorContextItem(
                OrchestratorContextKind::Assistant,
                "Ecco l'analisi del traffico.",
                turnId: 'cmpl_01j5abcdefghjkmnpqrstvwxyz',
            ),
        ]);

        $this->assertSame([
            'orchestrator_context' => [
                ['kind' => 'compacted', 'content' => 'Compattazione del contesto.'],
                ['kind' => 'assistant', 'content' => "Ecco l'analisi del traffico.", 'turn_id' => 'cmpl_01j5abcdefghjkmnpqrstvwxyz'],
            ],
        ], $request->toArray());
    }

    public function test_request_serialization_shapes(): void
    {
        $this->assertSame(['team_id' => 'team_01j5abcdefghjkmnpqrstvwxyz'], (new ChatCreateRequest('team_01j5abcdefghjkmnpqrstvwxyz'))->toArray());
        $this->assertSame(['message' => 'Summarize the last week of traffic'], (new ChatMessageRequest('Summarize the last week of traffic'))->toArray());
        $this->assertSame(['destination_chat_id' => 'cht_01j5abcdefghjkmnpqrstuvwxyZ'], (new ForkChatRequest('cht_01j5abcdefghjkmnpqrstuvwxyZ'))->toArray());
    }

    public function test_tool_output_request_serializes_is_error_default(): void
    {
        $request = new ToolOutputRequest(
            callId: 'tc_01j5abcdefghjkmnpqrstvwxyz',
            output: '{"order_id": "ord_123", "total_cents": 4900}',
        );

        $this->assertSame([
            'call_id' => 'tc_01j5abcdefghjkmnpqrstvwxyz',
            'output' => '{"order_id": "ord_123", "total_cents": 4900}',
            'is_error' => false,
        ], $request->toArray());
    }

    public function test_approval_decision_request_serializes_reason_when_present(): void
    {
        $withReason = new ApprovalDecisionRequest('appr_01j5abcdefghjkmnpqrstvwxyz', true, 'Approved, keep the budget under 10 dollars');
        $withoutReason = new ApprovalDecisionRequest('appr_01j5abcdefghjkmnpqrstvwxyz', false);

        $this->assertSame('Approved, keep the budget under 10 dollars', $withReason->toArray()['reason']);
        $this->assertArrayNotHasKey('reason', $withoutReason->toArray());
        $this->assertFalse($withoutReason->toArray()['approve']);
    }

    public function test_steer_request_omits_null_target_agent(): void
    {
        $targeted = new SteerRequest('Prefer the cheaper provider', 'ag_01j5abcdefghjkmnpqrstvwxyz');
        $orchestrator = new SteerRequest('Prefer the cheaper provider');

        $this->assertSame('ag_01j5abcdefghjkmnpqrstvwxyz', $targeted->toArray()['target_agent_id']);
        $this->assertArrayNotHasKey('target_agent_id', $orchestrator->toArray());
    }
}
