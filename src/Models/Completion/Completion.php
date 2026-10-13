<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Completion;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Models\Usage\BillingState;
use Digitalygo\Envai\Models\Usage\UsageCost;

/**
 * Metadata-only record of a model turn inside a chat, id `cmpl_<ulid>`.
 * It never contains message content: usage, cost and lifecycle fields
 * summarize the turn while the content lives in the execution plane stream.
 *
 * `usage` is preserved as a wire-faithful JsonObject, `status` and
 * `finish_reason` are enum-backed and `cost_usd` keeps sub-cent precision.
 * Timestamps stay ISO 8601 strings (wire-faithful), never parsed into
 * DateTimeImmutable.
 */
final readonly class Completion implements ArrayHydratable
{
    /**
     * @param  list<string>  $modelsSubagents
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $chatId,
        public string $teamId,
        public string $userId,
        public ?string $modelOrchestrator,
        public array $modelsSubagents,
        public string $startedAt,
        public JsonObject $usage,
        public ?float $costUsd,
        public CompletionStatus $status,
        public ?CompletionFinishReason $finishReason,
        public string $createdAt,
        public string $updatedAt,
        public ?string $finishedAt = null,
        public ?int $durationMs = null,
        public ?string $contentHash = null,
        public array $extra = [],
        public ?BillingState $billing = null,
        public ?UsageCost $usageCost = null,
        public ?CompletionTerminalSnapshot $terminalSnapshot = null,
    ) {
        if ($terminalSnapshot !== null && $terminalSnapshot->data->get('turn_id') !== $id) {
            throw new \InvalidArgumentException('Terminal snapshot turn_id does not match its completion.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'Completion';

        return new self(
            id: Wire::string($data, 'id', $context),
            workspaceId: Wire::string($data, 'workspace_id', $context),
            chatId: Wire::string($data, 'chat_id', $context),
            teamId: Wire::string($data, 'team_id', $context),
            userId: Wire::string($data, 'user_id', $context),
            modelOrchestrator: Wire::nullableString($data, 'model_orchestrator', $context),
            modelsSubagents: Wire::stringList($data, 'models_subagents', $context),
            startedAt: Wire::string($data, 'started_at', $context),
            usage: Wire::jsonObject($data, 'usage', $context),
            costUsd: Wire::nullableFloat($data, 'cost_usd', $context),
            status: Wire::enum($data, 'status', CompletionStatus::class, $context),
            finishReason: Wire::nullableEnum($data, 'finish_reason', CompletionFinishReason::class, $context),
            finishedAt: Wire::nullableString($data, 'finished_at', $context),
            durationMs: Wire::nullableInt($data, 'duration_ms', $context),
            contentHash: Wire::nullableString($data, 'content_hash', $context),
            createdAt: Wire::string($data, 'created_at', $context),
            updatedAt: Wire::string($data, 'updated_at', $context),
            billing: isset($data['billing']) ? BillingState::fromArray(Wire::objectArray($data, 'billing', $context)) : null,
            usageCost: isset($data['usage_cost']) ? UsageCost::fromArray(Wire::objectArray($data, 'usage_cost', $context)) : null,
            terminalSnapshot: isset($data['terminal_snapshot']) ? CompletionTerminalSnapshot::fromArray(Wire::objectArray($data, 'terminal_snapshot', $context)) : null,
            extra: Wire::extra($data, [
                'terminal_snapshot',
                'billing',
                'usage_cost',
                'id',
                'workspace_id',
                'chat_id',
                'team_id',
                'user_id',
                'model_orchestrator',
                'models_subagents',
                'started_at',
                'usage',
                'cost_usd',
                'status',
                'finish_reason',
                'finished_at',
                'duration_ms',
                'content_hash',
                'created_at',
                'updated_at',
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'workspace_id' => $this->workspaceId,
            'chat_id' => $this->chatId,
            'team_id' => $this->teamId,
            'user_id' => $this->userId,
            'model_orchestrator' => $this->modelOrchestrator,
            'models_subagents' => $this->modelsSubagents,
            'started_at' => $this->startedAt,
            'usage' => $this->usage->toArray(),
            'cost_usd' => $this->costUsd,
            'status' => $this->status->value,
            'finish_reason' => $this->finishReason?->value,
            'finished_at' => $this->finishedAt,
            'duration_ms' => $this->durationMs,
            'content_hash' => $this->contentHash,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];

        if ($this->billing !== null) {
            $payload['billing'] = $this->billing->toArray();
        }
        if ($this->usageCost !== null) {
            $payload['usage_cost'] = $this->usageCost->toArray();
        }

        if ($this->terminalSnapshot !== null) {
            $payload['terminal_snapshot'] = $this->terminalSnapshot->toArray();
        }

        return array_merge($payload, $this->extra);
    }
}
