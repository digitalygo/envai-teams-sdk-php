<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Chat\DeliveryOutcome;

final readonly class ChatDeliveryDeliveredEvent extends AtpEvent
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        EventEnvelope $envelope,
        public string $eventId,
        public string $taskId,
        public string $originTurnId,
        public DeliveryOutcome $status,
        public ?string $deliveryTurnId = null,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'chat.delivery.delivered';
    }
}
