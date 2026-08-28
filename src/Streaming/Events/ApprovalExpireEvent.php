<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * An approval reached its deadline and the branch fails closed.
 */
final readonly class ApprovalExpireEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $approvalId,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'approval.expire';
    }
}
