<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Acknowledgment for a restore request. The chat was rehydrated from the
 * supplied orchestrator context; `turn_id` is always null because restore
 * does not open a turn.
 */
final readonly class RestoreChatAccepted implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $status,
        public ?string $turnId = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'RestoreChatAccepted';

        return new self(
            status: Wire::string($data, 'status', $context),
            turnId: Wire::nullableString($data, 'turn_id', $context),
            extra: Wire::extra($data, ['status', 'turn_id']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['status' => $this->status];

        if ($this->turnId !== null) {
            $payload['turn_id'] = $this->turnId;
        }

        return array_merge($payload, $this->extra);
    }
}
