<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

/**
 * Payload to resolve a pending approval (`approval.request`). `reason` is an
 * optional user note attached to the decision.
 */
final readonly class ApprovalDecisionRequest
{
    public function __construct(
        public string $approvalId,
        public bool $approve,
        public ?string $reason = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'approval_id' => $this->approvalId,
            'approve' => $this->approve,
        ];

        if ($this->reason !== null) {
            $payload['reason'] = $this->reason;
        }

        return $payload;
    }
}
