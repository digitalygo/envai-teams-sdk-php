<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

final readonly class ChatTitleUpdatedEvent extends AtpEvent
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        EventEnvelope $envelope,
        public string $title,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'chat.title.updated';
    }
}
