<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Acknowledgment for an accepted asynchronous chat control operation (cancel,
 * steer). Carries the `turn_id` of the affected turn.
 */
final readonly class TurnAccepted implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $turnId,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'TurnAccepted';

        return new self(
            turnId: Wire::string($data, 'turn_id', $context),
            extra: Wire::extra($data, ['turn_id']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(['turn_id' => $this->turnId], $this->extra);
    }
}
