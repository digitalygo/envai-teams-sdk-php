<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class DeliveryAccepted implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $eventId,
        public DeliveryStatus $status,
        public bool $duplicate,
        public ?string $turnId = null,
        public ?string $error = null,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            eventId: Wire::string($data, 'event_id', 'DeliveryAccepted'),
            status: DeliveryStatus::from(Wire::string($data, 'status', 'DeliveryAccepted')),
            duplicate: Wire::bool($data, 'duplicate', 'DeliveryAccepted'),
            turnId: Wire::nullableString($data, 'turn_id', 'DeliveryAccepted'),
            error: Wire::nullableString($data, 'error', 'DeliveryAccepted'),
            extra: Wire::extra($data, ['event_id', 'status', 'duplicate', 'turn_id', 'error']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'status' => $this->status->value,
            'duplicate' => $this->duplicate,
        ] + ($this->turnId === null ? [] : ['turn_id' => $this->turnId]) + ($this->error === null ? [] : ['error' => $this->error]) + $this->extra;
    }
}
