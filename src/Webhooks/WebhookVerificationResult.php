<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

final readonly class WebhookVerificationResult
{
    public WebhookVerificationMetadata $metadata;

    public WebhookVerificationMetadata $verifiedMetadata;

    public function __construct(
        public string $eventId,
        public string $eventType,
        public ?string $timestamp = null,
        public ?string $requestId = null,
        ?WebhookVerificationMetadata $metadata = null,
    ) {
        self::assertIdentity($eventId, 'eventId');
        self::assertIdentity($eventType, 'eventType');
        self::assertOptional($timestamp, 'timestamp');
        self::assertOptional($requestId, 'requestId');

        $this->metadata = $metadata ?? WebhookVerificationMetadata::none();
        $this->verifiedMetadata = $this->metadata;
    }

    public static function authenticated(
        string $eventId,
        string $eventType,
        ?string $timestamp = null,
        ?string $requestId = null,
        ?WebhookVerificationMetadata $metadata = null,
    ): self {
        return new self($eventId, $eventType, $timestamp, $requestId, $metadata);
    }

    private static function assertIdentity(string $value, string $name): void
    {
        if (trim($value) === '') {
            throw new WebhookIdentityException(sprintf('Authenticated webhook %s must not be empty.', $name));
        }

        if (preg_match('/[\r\n\x00-\x1F\x7F]/', $value) === 1) {
            throw new WebhookIdentityException(sprintf('Authenticated webhook %s contains unsafe control characters.', $name));
        }
    }

    private static function assertOptional(?string $value, string $name): void
    {
        if ($value !== null && (trim($value) === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $value) === 1)) {
            throw new WebhookVerificationException(sprintf('Verified webhook %s is invalid.', $name));
        }
    }
}
