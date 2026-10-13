<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Completion;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Json\JsonObject;

final readonly class CompletionTerminalSnapshot implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $eventId,
        public string $occurredAt,
        public string $eventType,
        public JsonObject $data,
        public array $extra = [],
        public ?int $v = null,
        public ?int $acceptedProtocolVersion = null,
    ) {
        foreach ([$v, $acceptedProtocolVersion] as $version) {
            if ($version !== null && ! in_array($version, [2, 3], true)) {
                throw new \InvalidArgumentException('Invalid snapshot protocol version.');
            }
        }
        if ($eventId === '' || ! in_array($eventType, ['turn.completed', 'turn.failed', 'turn.cancelled'], true)) {
            throw new \InvalidArgumentException('Invalid completion terminal identity or event type.');
        }
        $turnId = $data->get('turn_id');
        if (! is_string($turnId) || $turnId === '') {
            throw new \InvalidArgumentException('Terminal snapshot requires its completion turn_id.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            eventId: Wire::string($data, 'event_id', self::class),
            occurredAt: Wire::string($data, 'occurred_at', self::class),
            eventType: Wire::string($data, 'event_type', self::class),
            data: Wire::jsonObject($data, 'data', self::class),
            extra: Wire::extra($data, ['event_id', 'occurred_at', 'event_type', 'data', 'v', 'accepted_protocol_version']),
            v: Wire::nullableInt($data, 'v', self::class),
            acceptedProtocolVersion: Wire::nullableInt($data, 'accepted_protocol_version', self::class),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, $this->v === null ? [] : ['v' => $this->v], $this->acceptedProtocolVersion === null ? [] : ['accepted_protocol_version' => $this->acceptedProtocolVersion], [
            'event_id' => $this->eventId,
            'occurred_at' => $this->occurredAt,
            'event_type' => $this->eventType,
            'data' => $this->data->toArray(),
        ]);
    }
}
