<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Webhooks;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Webhooks\WebhookPayload;
use Digitalygo\Envai\Webhooks\WebhookReceived;
use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\WebhookRequestMetadata;
use Digitalygo\Envai\Webhooks\WebhookVerificationException;
use Digitalygo\Envai\Webhooks\WebhookVerificationMetadata;
use Digitalygo\Envai\Webhooks\WebhookVerificationResult;
use PHPUnit\Framework\TestCase;

final class WebhookRequestTest extends TestCase
{
    public function test_request_normalizes_case_insensitive_duplicate_headers(): void
    {
        $request = new WebhookRequest(
            method: 'POST',
            uri: '/webhooks',
            headers: [
                'Content-Type' => 'application/json',
                'X-Trace' => ['first', 'second'],
                'x-trace' => 'third',
            ],
            rawBody: '{"event":"created"}',
            metadata: new WebhookRequestMetadata('192.0.2.10', 'HTTP/2', 'hooks.example', 443),
        );

        $this->assertSame(['application/json'], $request->headerValues('CONTENT-TYPE'));
        $this->assertSame(['first', 'second', 'third'], $request->headerValues('x-TRACE'));
        $this->assertSame('first', $request->header('X-Trace'));
        $this->assertTrue($request->hasHeader('content-type'));
        $this->assertSame('{"event":"created"}', $request->rawBody);
        $this->assertSame('192.0.2.10', $request->metadata->remoteAddress);
        $this->assertSame(443, $request->metadata->serverPort);
    }

    public function test_request_accepts_only_safe_allowlisted_metadata(): void
    {
        $request = new WebhookRequest(
            'POST',
            '/webhooks',
            metadata: [
                'REMOTE_ADDR' => '192.0.2.11',
                'SERVER_PROTOCOL' => 'HTTP/1.1',
                'SERVER_PORT' => 8443,
            ],
        );

        $this->assertSame([
            'remote_address' => '192.0.2.11',
            'server_protocol' => 'HTTP/1.1',
            'server_port' => 8443,
        ], $request->metadata->toArray());

        $this->expectException(\InvalidArgumentException::class);
        WebhookRequestMetadata::fromArray(['authorization' => 'opaque']);
    }

    public function test_payload_preserves_unknown_fields_and_object_list_semantics(): void
    {
        $wire = '{"known":true,"unknown":{"empty":{},"list":[]},"items":[{}]}';
        $payload = WebhookPayload::fromJson($wire);

        $this->assertTrue($payload->isObject());
        $this->assertSame($wire, $payload->toJson());
        $this->assertFalse($payload->isList());
        $this->assertSame(true, $payload->get('known'));
        $this->assertSame(['known' => true, 'unknown' => ['empty' => [], 'list' => []], 'items' => [[]]], $payload->toArray());
        $this->assertSame('{}', WebhookPayload::fromJson('{}')->toJson());
        $this->assertStringContainsString('"empty":{}', $payload->toJson());
        $this->assertStringContainsString('"list":[]', $payload->toJson());
        $this->assertInstanceOf(JsonObject::class, $payload->get('unknown'));
        $this->assertInstanceOf(JsonArray::class, $payload->get('items'));
    }

    public function test_root_list_is_distinguished(): void
    {
        $payload = WebhookPayload::fromJson('[{"unknown":1}]');

        $this->assertTrue($payload->isList());
        $this->assertSame('list', $payload->rootType->value);
        $this->assertCount(1, $payload->list()->toArray());
    }

    public function test_verification_metadata_requires_an_explicit_allowlist_and_rejects_sensitive_names(): void
    {
        $metadata = WebhookVerificationMetadata::fromAllowlist(
            ['verification_method' => 'external'],
            ['verification_method'],
        );
        $result = new WebhookVerificationResult('evt_1', 'image.updated', metadata: $metadata);

        $this->assertSame(['verification_method' => 'external'], $result->metadata->toArray());
        $this->assertSame($result->metadata, $result->verifiedMetadata);

        $this->expectException(WebhookVerificationException::class);
        WebhookVerificationMetadata::fromAllowlist(['signature' => 'opaque'], ['signature']);
    }

    public function test_received_event_contains_only_safe_data(): void
    {
        $event = new WebhookReceived(
            'evt_1',
            'image.updated',
            WebhookPayload::fromJson('{"unknown":true}'),
            requestId: 'req_1',
            verificationMetadata: WebhookVerificationMetadata::fromAllowlist(['key_id' => 'public-1'], ['key_id']),
        );

        $encoded = json_encode($event, JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('rawBody', get_object_vars($event));
        $this->assertArrayNotHasKey('headers', get_object_vars($event));
        $this->assertStringNotContainsString('authorization', strtolower($encoded));
        $this->assertStringNotContainsString('signature', strtolower($encoded));
        $this->assertStringNotContainsString('secret', strtolower($encoded));
        $this->assertStringNotContainsString('token', strtolower($encoded));
        $this->assertStringNotContainsString('raw_body', strtolower($encoded));
        $this->assertStringContainsString('evt_1', $encoded);
    }
}
