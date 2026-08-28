<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\System\Capabilities;
use Digitalygo\Envai\Models\System\HealthStatus;
use Digitalygo\Envai\Models\System\Jwk;
use Digitalygo\Envai\Models\System\JwksDocument;
use PHPUnit\Framework\TestCase;

final class SystemTest extends TestCase
{
    public function test_capabilities_hydrates_from_realistic_payload(): void
    {
        $capabilities = Capabilities::fromArray([
            'protocol_version' => 2,
            'families' => ['turn', 'agent'],
            'event_types' => [
                'ping',
                'turn.started',
                'turn.completed',
                'turn.cancelled',
                'turn.failed',
                'turn.steered',
                'agent.started',
                'agent.done',
                'agent.failed',
                'approval.request',
                'approval.response',
                'approval.expire',
            ],
            'tool_kinds' => ['server', 'client', 'knowledge', 'code'],
            'features' => ['replay', 'fork', 'restore', 'steer', 'ping', 'background_delegation', 'client_tools'],
        ]);

        $this->assertSame(2, $capabilities->protocolVersion);
        $this->assertSame(['turn', 'agent'], $capabilities->families);
        $this->assertSame(12, count($capabilities->eventTypes));
        $this->assertSame(['server', 'client', 'knowledge', 'code'], $capabilities->toolKinds);
        $this->assertContains('client_tools', $capabilities->features);
        $this->assertSame([], $capabilities->extra);
    }

    public function test_capabilities_preserves_unknown_top_level_key_in_extra(): void
    {
        $capabilities = Capabilities::fromArray([
            'protocol_version' => 2,
            'families' => ['turn'],
            'event_types' => ['ping'],
            'tool_kinds' => ['server'],
            'features' => [],
            'beta_flags' => ['realtime'],
            'server_rev' => '2026.08.1',
        ]);

        $this->assertSame(['beta_flags' => ['realtime'], 'server_rev' => '2026.08.1'], $capabilities->extra);

        $serialized = $capabilities->toArray();
        $this->assertSame(['realtime'], $serialized['beta_flags']);
        $this->assertSame('2026.08.1', $serialized['server_rev']);
        $this->assertSame(2, $serialized['protocol_version']);
    }

    public function test_capabilities_throws_on_missing_required_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Capabilities payload is missing required field "protocol_version"');

        Capabilities::fromArray(['families' => [], 'event_types' => [], 'tool_kinds' => [], 'features' => []]);
    }

    public function test_health_status_hydrates_and_preserves_extra(): void
    {
        $status = HealthStatus::fromArray([
            'status' => 'up',
            'timestamp' => '2026-03-01T12:00:00+00:00',
            'app' => 'Laravel',
            'env' => 'local',
            'revision' => 'abc123',
        ]);

        $this->assertSame('up', $status->status);
        $this->assertSame('2026-03-01T12:00:00+00:00', $status->timestamp);
        $this->assertSame('Laravel', $status->app);
        $this->assertSame('local', $status->env);
        $this->assertSame(['revision' => 'abc123'], $status->extra);
        $this->assertSame('up', $status->toArray()['status']);
    }

    public function test_health_status_defaults_null_fields(): void
    {
        $status = HealthStatus::fromArray(['status' => 'up']);

        $this->assertSame('up', $status->status);
        $this->assertNull($status->timestamp);
        $this->assertNull($status->app);
        $this->assertNull($status->env);
    }

    public function test_health_status_throws_without_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HealthStatus payload is missing required field "status"');

        HealthStatus::fromArray(['timestamp' => '2026-03-01T12:00:00+00:00']);
    }

    public function test_jwk_hydrates_all_fields(): void
    {
        $jwk = Jwk::fromArray([
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => 'dD07wck58mMvxA2ouG0EGZe9DUhqTZmGJVpAJyM8VHE',
            'kid' => 'sig_01j5abcdefghjkmnpqrstvwxyz',
            'use' => 'sig',
            'alg' => 'EdDSA',
        ]);

        $this->assertSame('OKP', $jwk->kty);
        $this->assertSame('Ed25519', $jwk->crv);
        $this->assertSame('EdDSA', $jwk->alg);
        $this->assertSame('sig_01j5abcdefghjkmnpqrstvwxyz', $jwk->kid);
        $this->assertSame([], $jwk->extra);
    }

    public function test_jwks_document_hydrates_keys_and_preserves_extra(): void
    {
        $document = JwksDocument::fromArray([
            'keys' => [
                [
                    'kty' => 'OKP',
                    'crv' => 'Ed25519',
                    'x' => 'dD07wck58mMvxA2ouG0EGZe9DUhqTZmGJVpAJyM8VHE',
                    'kid' => 'sig_01j5abcdefghjkmnpqrstvwxyz',
                    'use' => 'sig',
                    'alg' => 'EdDSA',
                ],
                [
                    'kty' => 'OKP',
                    'crv' => 'Ed25519',
                    'x' => '9jQr7xLm2kPqBzWdVeXcA1fGhIjKlMnOpQrStUvWxYz',
                    'kid' => 'sig_01j5abcdefghjkmnpqrstvwxyA',
                    'use' => 'sig',
                    'alg' => 'EdDSA',
                ],
            ],
            'next_rotation' => '2026-09-01T00:00:00+00:00',
        ]);

        $this->assertCount(2, $document->keys);
        $this->assertInstanceOf(Jwk::class, $document->keys[0]);
        $this->assertSame('sig_01j5abcdefghjkmnpqrstvwxyA', $document->keys[1]->kid);
        $this->assertSame(['next_rotation' => '2026-09-01T00:00:00+00:00'], $document->extra);
    }

    public function test_jwks_document_round_trips_through_to_array(): void
    {
        $payload = [
            'keys' => [
                [
                    'kty' => 'OKP',
                    'crv' => 'Ed25519',
                    'x' => 'dD07wck58mMvxA2ouG0EGZe9DUhqTZmGJVpAJyM8VHE',
                    'kid' => 'sig_01j5abcdefghjkmnpqrstvwxyz',
                    'use' => 'sig',
                    'alg' => 'EdDSA',
                ],
            ],
        ];

        $this->assertEquals($payload, JwksDocument::fromArray($payload)->toArray());
    }

    public function test_jwks_document_throws_on_missing_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('JwksDocument payload is missing required field "keys"');

        JwksDocument::fromArray([]);
    }
}
