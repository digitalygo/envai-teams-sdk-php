<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

final readonly class ChatCronWakeupEvent extends AtpEvent
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        EventEnvelope $envelope,
        public string $cronId,
        public string $occurrenceId,
        public string $scheduledAt,
        public string $deliveredAt,
        public string $prompt,
        public string $targetMode,
        public string $dispatchMode,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'chat.cron.wakeup';
    }
}
