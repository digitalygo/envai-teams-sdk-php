<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Integration\Laravel\Events\EnvaiWebhookReceived;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Webhooks\WebhookPayload;
use Digitalygo\Envai\Webhooks\WebhookReceived;
use Digitalygo\Envai\Webhooks\WebhookVerificationMetadata;

final class EnvaiWebhookReceivedTest extends IntegrationTestCase
{
    public function test_projects_safe_fields_and_preserves_nested_object_list_json_shape(): void
    {
        $receivedAt = new \DateTimeImmutable('2026-08-30T12:34:56+00:00');
        $coreEvent = new WebhookReceived(
            eventId: 'evt_123',
            eventType: 'image.completed',
            payload: WebhookPayload::fromJson('{"nested":{"empty":{},"items":[{}]}}'),
            timestamp: '2026-08-30T12:34:55Z',
            requestId: 'req_123',
            receivedAt: $receivedAt,
            verificationMetadata: WebhookVerificationMetadata::fromAllowlist(
                ['key_id' => 'public-1', 'method' => 'ed25519'],
                ['key_id', 'method'],
            ),
        );

        $event = new EnvaiWebhookReceived($coreEvent, 'production');
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $serialized = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('evt_123', $event->eventId);
        $this->assertSame('image.completed', $event->eventType);
        $this->assertInstanceOf(JsonObject::class, $event->payload);
        $this->assertSame('2026-08-30T12:34:55Z', $event->timestamp);
        $this->assertSame('req_123', $event->requestId);
        $this->assertSame($receivedAt, $event->receivedAt);
        $this->assertSame('production', $event->connectionName);
        $this->assertSame(['key_id' => 'public-1', 'method' => 'ed25519'], $event->verificationMetadata->toArray());
        $this->assertSame([
            'event_id' => 'evt_123',
            'event_type' => 'image.completed',
            'payload' => ['nested' => ['empty' => [], 'items' => [[]]]],
            'received_at' => '2026-08-30T12:34:56+00:00',
            'connection_name' => 'production',
            'verification_metadata' => ['key_id' => 'public-1', 'method' => 'ed25519'],
            'timestamp' => '2026-08-30T12:34:55Z',
            'request_id' => 'req_123',
        ], $serialized);
        $this->assertStringContainsString('"empty":{}', $encoded);
        $this->assertStringContainsString('"items":[{}]', $encoded);
    }

    public function test_serialization_omits_nullable_verified_fields_without_exposing_request_secrets(): void
    {
        $coreEvent = new WebhookReceived(
            'evt_safe',
            'created',
            WebhookPayload::fromJson('{"safe":true}'),
        );

        $event = new EnvaiWebhookReceived($coreEvent, 'production');
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $serialized = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $publicProperties = get_object_vars($event);

        $this->assertArrayNotHasKey('timestamp', $serialized);
        $this->assertArrayNotHasKey('request_id', $serialized);
        $this->assertArrayNotHasKey('rawBody', $publicProperties);
        $this->assertArrayNotHasKey('headers', $publicProperties);
        $this->assertArrayNotHasKey('signature', $publicProperties);
        $this->assertStringNotContainsString('authorization', strtolower($encoded));
        $this->assertStringNotContainsString('secret', strtolower($encoded));
        $this->assertStringNotContainsString('token', strtolower($encoded));
        $this->assertStringNotContainsString('signature', strtolower($encoded));
        $this->assertStringNotContainsString('raw_body', strtolower($encoded));
    }
}
