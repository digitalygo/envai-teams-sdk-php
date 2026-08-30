<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\Events;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Webhooks\WebhookReceived;
use Digitalygo\Envai\Webhooks\WebhookVerificationMetadata;

final readonly class EnvaiWebhookReceived implements \JsonSerializable
{
    public readonly string $eventId;

    public readonly string $eventType;

    public readonly JsonObject|JsonArray $payload;

    public readonly ?string $timestamp;

    public readonly ?string $requestId;

    public readonly \DateTimeImmutable $receivedAt;

    public readonly WebhookVerificationMetadata $verificationMetadata;

    public function __construct(
        WebhookReceived $event,
        public string $connectionName,
    ) {
        if (trim($connectionName) === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $connectionName) === 1) {
            throw new \InvalidArgumentException('The webhook connection name must be non-empty and safe.');
        }

        $this->eventId = $event->eventId;
        $this->eventType = $event->eventType;
        $this->payload = $event->payload->root;
        $this->timestamp = $event->timestamp;
        $this->requestId = $event->requestId;
        $this->receivedAt = $event->receivedAt;
        $this->verificationMetadata = $event->verificationMetadata;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'payload' => self::jsonValue($this->payload),
            'received_at' => $this->receivedAt->format(DATE_ATOM),
            'connection_name' => $this->connectionName,
            'verification_metadata' => $this->verificationMetadata->toArray(),
        ];

        if ($this->timestamp !== null) {
            $data['timestamp'] = $this->timestamp;
        }

        if ($this->requestId !== null) {
            $data['request_id'] = $this->requestId;
        }

        return $data;
    }

    private static function jsonValue(mixed $value): object|array|string|int|float|bool|null
    {
        if ($value instanceof JsonObject) {
            $object = new \stdClass;

            foreach ($value->toArray() as $key => $item) {
                $object->{$key} = self::jsonValue($item);
            }

            return $object;
        }

        if ($value instanceof JsonArray) {
            $items = [];

            foreach ($value->toArray() as $item) {
                $items[] = self::jsonValue($item);
            }

            return $items;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $items = [];

                foreach ($value as $item) {
                    $items[] = self::jsonValue($item);
                }

                return $items;
            }

            $object = new \stdClass;

            foreach ($value as $key => $item) {
                $object->{(string) $key} = self::jsonValue($item);
            }

            return $object;
        }

        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        throw new \LogicException('The webhook payload contains an unsupported value.');
    }
}
