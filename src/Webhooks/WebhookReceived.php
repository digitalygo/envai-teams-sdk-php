<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

final readonly class WebhookReceived implements \JsonSerializable
{
    public readonly \DateTimeImmutable $receivedAt;

    public readonly WebhookVerificationMetadata $verificationMetadata;

    public readonly WebhookPayload $payload;

    public function __construct(
        public string $eventId,
        public string $eventType,
        WebhookPayload $payload,
        public ?string $timestamp = null,
        public ?string $requestId = null,
        ?\DateTimeImmutable $receivedAt = null,
        ?WebhookVerificationMetadata $verificationMetadata = null,
    ) {
        self::assertIdentity($eventId, 'eventId');
        self::assertIdentity($eventType, 'eventType');
        self::assertOptional($timestamp, 'timestamp');
        self::assertOptional($requestId, 'requestId');

        $this->receivedAt = $receivedAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->verificationMetadata = $verificationMetadata ?? WebhookVerificationMetadata::none();
        $this->payload = $payload->withoutWirePayload();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'event_id' => $this->eventId,
            'event_type' => $this->eventType,
            'payload' => $this->payload->toArray(),
            'received_at' => $this->receivedAt->format(DATE_ATOM),
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

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = $this->toArray();
        $data['payload'] = $this->payload->jsonSerialize();

        return $data;
    }

    private static function assertIdentity(string $value, string $name): void
    {
        if (trim($value) === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $value) === 1) {
            throw new WebhookIdentityException(sprintf('Webhook %s must be non-empty and safe.', $name));
        }
    }

    private static function assertOptional(?string $value, string $name): void
    {
        if ($value !== null && (trim($value) === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $value) === 1)) {
            throw new WebhookVerificationException(sprintf('Webhook %s is invalid.', $name));
        }
    }
}
