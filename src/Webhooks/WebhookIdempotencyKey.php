<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

final readonly class WebhookIdempotencyKey
{
    public function __construct(
        public string $scope,
        public string $eventId,
        public string $payloadHash,
        public ?string $eventType = null,
        public ?string $requestId = null,
    ) {
        self::assertNonEmpty($scope, 'scope');
        self::assertNonEmpty($eventId, 'eventId');
        self::assertNonEmpty($payloadHash, 'payloadHash');
        self::assertOptional($eventType, 'eventType');
        self::assertOptional($requestId, 'requestId');
    }

    private static function assertNonEmpty(string $value, string $name): void
    {
        if (trim($value) === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf('Webhook %s must be non-empty and safe.', $name));
        }
    }

    private static function assertOptional(?string $value, string $name): void
    {
        if ($value !== null && (trim($value) === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $value) === 1)) {
            throw new \InvalidArgumentException(sprintf('Webhook %s must be non-empty and safe.', $name));
        }
    }
}
