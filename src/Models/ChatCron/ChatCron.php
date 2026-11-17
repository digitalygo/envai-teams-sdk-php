<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ChatCron;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/** Public chat cron resource. Origin key, turn and call identifiers are never exposed. */
final readonly class ChatCron implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $originChatId,
        public string $teamId,
        public ChatCronTargetMode $targetMode,
        public string $prompt,
        public ?int $intervalMinutes,
        public ?string $dailyAt,
        public ?string $timezone,
        public ?string $startAt,
        public ?int $repeatCount,
        public ?string $until,
        public ChatCronStatus $status,
        public int $scheduledCount,
        public int $deliveryCount,
        public ?string $nextDueAt,
        public string $maxUntil,
        public string $createdAt,
        public string $updatedAt,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $context = 'ChatCron';

        return new self(
            id: Wire::string($data, 'id', $context),
            workspaceId: Wire::string($data, 'workspace_id', $context),
            originChatId: Wire::string($data, 'origin_chat_id', $context),
            teamId: Wire::string($data, 'team_id', $context),
            targetMode: Wire::enum($data, 'target_mode', ChatCronTargetMode::class, $context),
            prompt: Wire::string($data, 'prompt', $context),
            intervalMinutes: Wire::nullableInt($data, 'interval_minutes', $context),
            dailyAt: Wire::nullableString($data, 'daily_at', $context),
            timezone: Wire::nullableString($data, 'timezone', $context),
            startAt: Wire::nullableString($data, 'start_at', $context),
            repeatCount: Wire::nullableInt($data, 'repeat_count', $context),
            until: Wire::nullableString($data, 'until', $context),
            status: Wire::enum($data, 'status', ChatCronStatus::class, $context),
            scheduledCount: Wire::int($data, 'scheduled_count', $context),
            deliveryCount: Wire::int($data, 'delivery_count', $context),
            nextDueAt: Wire::nullableString($data, 'next_due_at', $context),
            maxUntil: Wire::string($data, 'max_until', $context),
            createdAt: Wire::string($data, 'created_at', $context),
            updatedAt: Wire::string($data, 'updated_at', $context),
            extra: Wire::extra($data, [
                'id',
                'workspace_id',
                'origin_chat_id',
                'team_id',
                'target_mode',
                'prompt',
                'interval_minutes',
                'daily_at',
                'timezone',
                'start_at',
                'repeat_count',
                'until',
                'status',
                'scheduled_count',
                'delivery_count',
                'next_due_at',
                'max_until',
                'created_at',
                'updated_at',
            ]),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'workspace_id' => $this->workspaceId,
            'origin_chat_id' => $this->originChatId,
            'team_id' => $this->teamId,
            'target_mode' => $this->targetMode->value,
            'prompt' => $this->prompt,
            'interval_minutes' => $this->intervalMinutes,
            'daily_at' => $this->dailyAt,
            'timezone' => $this->timezone,
            'start_at' => $this->startAt,
            'repeat_count' => $this->repeatCount,
            'until' => $this->until,
            'status' => $this->status->value,
            'scheduled_count' => $this->scheduledCount,
            'delivery_count' => $this->deliveryCount,
            'next_due_at' => $this->nextDueAt,
            'max_until' => $this->maxUntil,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ], $this->extra);
    }
}
