<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\Completion\Completion;
use Digitalygo\Envai\Models\Completion\CompletionFilters;
use Digitalygo\Envai\Models\Completion\CompletionFinishReason;
use Digitalygo\Envai\Models\Completion\CompletionStatus;
use Digitalygo\Envai\Models\Json\JsonObject;
use PHPUnit\Framework\TestCase;

final class CompletionTest extends TestCase
{
    public function test_completion_hydrates_from_realistic_payload(): void
    {
        $completion = Completion::fromArray([
            'id' => 'cmpl_01j5abcdefghjkmnpqrstvwxyz',
            'workspace_id' => 'wsp_01j5abcdefghjkmnpqrstvwxyz',
            'chat_id' => 'cht_01j5abcdefghjkmnpqrstvwxyz',
            'team_id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'model_orchestrator' => 'anthropic/claude-sonnet-4',
            'models_subagents' => ['openai/gpt-4o'],
            'started_at' => '2026-08-17T14:00:00+00:00',
            'finished_at' => '2026-08-17T14:02:03+00:00',
            'duration_ms' => 123000,
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 450, 'sprite_cpu_ms' => 512, 'sprite_ram_gb_ms' => 10],
            'cost_usd' => 0.42,
            'status' => 'succeeded',
            'finish_reason' => 'stop',
            'content_hash' => 'sha256:9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:02:04+00:00',
        ]);

        $this->assertSame('cmpl_01j5abcdefghjkmnpqrstvwxyz', $completion->id);
        $this->assertSame('anthropic/claude-sonnet-4', $completion->modelOrchestrator);
        $this->assertSame(['openai/gpt-4o'], $completion->modelsSubagents);
        $this->assertSame(CompletionStatus::Succeeded, $completion->status);
        $this->assertSame('succeeded', $completion->status->value);
        $this->assertSame(CompletionFinishReason::Stop, $completion->finishReason);
        $this->assertInstanceOf(JsonObject::class, $completion->usage);
        $this->assertSame(120, $completion->usage->get('prompt_tokens'));
        $this->assertSame(0.42, $completion->costUsd);
        $this->assertSame(123000, $completion->durationMs);
    }

    public function test_completion_preserves_unknown_nested_usage_keys(): void
    {
        $completion = Completion::fromArray(array_replace($this->basePayload(), [
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 450, 'cache_read_tokens' => 80],
        ]));

        $this->assertSame(80, $completion->usage->get('cache_read_tokens'));
    }

    public function test_completion_preserves_unknown_top_level_key_in_extra(): void
    {
        $completion = Completion::fromArray(array_replace($this->basePayload(), ['trace_id' => 'trc_01j5abcdefghjkmnpqrstvwxyz']));

        $this->assertSame(['trace_id' => 'trc_01j5abcdefghjkmnpqrstvwxyz'], $completion->extra);
    }

    public function test_completion_hydrates_running_state_with_nulls(): void
    {
        $completion = Completion::fromArray([
            'id' => 'cmpl_01j5abcdefghjkmnpqrstvwxyz',
            'workspace_id' => 'wsp_01j5abcdefghjkmnpqrstvwxyz',
            'chat_id' => 'cht_01j5abcdefghjkmnpqrstvwxyz',
            'team_id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'model_orchestrator' => null,
            'models_subagents' => [],
            'started_at' => '2026-08-17T14:00:00+00:00',
            'usage' => ['prompt_tokens' => 12],
            'cost_usd' => 0,
            'status' => 'running',
            'finish_reason' => null,
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:00:00+00:00',
        ]);

        $this->assertSame(CompletionStatus::Running, $completion->status);
        $this->assertNull($completion->finishReason);
        $this->assertNull($completion->modelOrchestrator);
        $this->assertNull($completion->finishedAt);
        $this->assertNull($completion->durationMs);
        $this->assertSame([], $completion->modelsSubagents);
    }

    public function test_completion_throws_on_unknown_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Completion payload field "status" has unknown value "pending"');

        Completion::fromArray(array_replace($this->basePayload(), ['status' => 'pending']));
    }

    public function test_completion_round_trips_enum_values(): void
    {
        $payload = array_replace($this->basePayload(), ['status' => 'canceled', 'finish_reason' => 'canceled']);

        $completion = Completion::fromArray($payload);

        $this->assertSame(CompletionStatus::Canceled, $completion->status);
        $this->assertSame(CompletionFinishReason::Canceled, $completion->finishReason);
        $this->assertSame('canceled', $completion->toArray()['status']);
        $this->assertSame('canceled', $completion->toArray()['finish_reason']);
    }

    public function test_completion_filters_serializes_enum_and_date_range_keys(): void
    {
        $filters = new CompletionFilters(
            status: CompletionStatus::Failed,
            modelOrchestrator: 'openai/gpt-4o',
            startedAtFrom: '2026-08-01T00:00:00+00:00',
            startedAtTo: '2026-08-31T23:59:59+00:00',
            perPage: 25,
        );

        $this->assertSame([
            'status' => 'failed',
            'model_orchestrator' => 'openai/gpt-4o',
            'started_at[from]' => '2026-08-01T00:00:00+00:00',
            'started_at[to]' => '2026-08-31T23:59:59+00:00',
            'per_page' => 25,
        ], $filters->toArray());
    }

    public function test_completion_filters_omits_null_filters(): void
    {
        $this->assertSame([], (new CompletionFilters)->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function basePayload(): array
    {
        return [
            'id' => 'cmpl_01j5abcdefghjkmnpqrstvwxyz',
            'workspace_id' => 'wsp_01j5abcdefghjkmnpqrstvwxyz',
            'chat_id' => 'cht_01j5abcdefghjkmnpqrstvwxyz',
            'team_id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'model_orchestrator' => 'anthropic/claude-sonnet-4',
            'models_subagents' => [],
            'started_at' => '2026-08-17T14:00:00+00:00',
            'usage' => ['prompt_tokens' => 120],
            'cost_usd' => 0.42,
            'status' => 'succeeded',
            'finish_reason' => 'stop',
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:02:04+00:00',
        ];
    }
}
