<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ChatCron;

/** Query filters for the workspace chat cron listing. */
final readonly class ChatCronFilters
{
    public function __construct(
        public ?string $originChatId = null,
        public ?ChatCronStatus $status = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [];

        if ($this->originChatId !== null) {
            $payload['origin_chat_id'] = $this->originChatId;
        }

        if ($this->status !== null) {
            $payload['status'] = $this->status->value;
        }

        return $payload;
    }
}
