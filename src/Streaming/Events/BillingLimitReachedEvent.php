<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

final readonly class BillingLimitReachedEvent extends AtpEvent
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        EventEnvelope $envelope,
        public string $reason,
        public string $scope,
        public string $currency,
        public ?string $resetAt,
        public ?string $amount,
        public ?string $remaining,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'billing.limit_reached';
    }
}
