<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Usage\BillingState;
use Digitalygo\Envai\Models\Usage\UsageCost;

/**
 * The turn ended successfully. `usage` is the turn total and `chatUsage` /
 * `chatCost` are informational chat lifetime totals (no cumulative budget:
 * the SDK never blocks a message because of them).
 */
final readonly class TurnCompletedEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $turnId,
        public Usage $usage,
        public ?float $cost,
        public Usage $chatUsage,
        public ?float $chatCost,
        public string $finishReason,
        array $extra = [],
        public ?BillingState $billing = null,
        public ?UsageCost $usageCost = null,
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'turn.completed';
    }
}
