<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * The user resolved an approval; the envelope `agent` is null because this
 * event echoes the user, not an agent.
 */
final readonly class ApprovalResponseEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $approvalId,
        public bool $approve,
        public string $reason,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'approval.response';
    }
}
