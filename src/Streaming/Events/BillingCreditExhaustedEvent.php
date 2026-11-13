<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

final readonly class BillingCreditExhaustedEvent extends AtpEvent
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        EventEnvelope $envelope,
        public string $reason,
        public string $scope,
        public string $currency,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'billing.credit_exhausted';
    }
}
