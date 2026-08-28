<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Models\ApiKey\ApiKey;
use Digitalygo\Envai\Models\ApiKey\ApiKeyCreated;
use Digitalygo\Envai\Models\ApiKey\ApiKeyCreateRequest;
use Digitalygo\Envai\Models\ApiKey\ApiKeyExpiry;
use Digitalygo\Envai\Models\ApiKey\ApiKeyScope;
use PHPUnit\Framework\TestCase;

final class ApiKeyTest extends TestCase
{
    public function test_api_key_hydrates_scopes_as_enums_and_preserves_extra(): void
    {
        $key = ApiKey::fromArray([
            'id' => 'key_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'ci-deploy',
            'scopes' => ['completions:write'],
            'expires_at' => '2026-09-09T00:00:00+00:00',
            'revoked_at' => null,
            'created_at' => '2026-08-10T00:00:00+00:00',
            'owner_team' => 'team_01j5abcdefghjkmnpqrstvwxyz',
        ]);

        $this->assertSame('key_01j5abcdefghjkmnpqrstvwxyz', $key->id);
        $this->assertSame('ci-deploy', $key->name);
        $this->assertSame([ApiKeyScope::CompletionsWrite], $key->scopes);
        $this->assertSame('completions:write', $key->scopes[0]->value);
        $this->assertSame('2026-09-09T00:00:00+00:00', $key->expiresAt);
        $this->assertNull($key->revokedAt);
        $this->assertSame(['owner_team' => 'team_01j5abcdefghjkmnpqrstvwxyz'], $key->extra);
    }

    public function test_api_key_throws_on_unknown_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ApiKey payload field "scopes" has unknown value "completions:read"');

        ApiKey::fromArray([
            'id' => 'key_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'ci-deploy',
            'scopes' => ['completions:read'],
            'expires_at' => null,
            'revoked_at' => null,
            'created_at' => '2026-08-10T00:00:00+00:00',
        ]);
    }

    public function test_api_key_created_wraps_token_as_sensitive_string(): void
    {
        $created = ApiKeyCreated::fromArray([
            'id' => 'key_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'ci-deploy',
            'scopes' => ['completions:write'],
            'expires_at' => '2026-09-09T00:00:00+00:00',
            'revoked_at' => null,
            'created_at' => '2026-08-10T00:00:00+00:00',
            'token' => 'envai_sk_01j5abcdefghjkmnpqrstvwxyz.eyJhbGciOiJFZERTQSJ9.signature',
        ]);

        $this->assertInstanceOf(SensitiveString::class, $created->token);
        $this->assertSame('envai_sk_01j5abcdefghjkmnpqrstvwxyz.eyJhbGciOiJFZERTQSJ9.signature', $created->token->reveal());
        $this->assertInstanceOf(ApiKey::class, $created->key);
        $this->assertSame('ci-deploy', $created->key->name);
        $this->assertSame([], $created->extra);
    }

    public function test_api_key_created_token_is_redacted_on_string_cast(): void
    {
        $created = ApiKeyCreated::fromArray($this->createdPayload());

        $this->assertSame('[redacted]', (string) $created->token);
        $this->assertStringNotContainsString('envai_sk_', (string) $created->token);
    }

    public function test_api_key_created_to_array_never_includes_token(): void
    {
        $created = ApiKeyCreated::fromArray($this->createdPayload());

        $serialized = $created->toArray();

        $this->assertArrayNotHasKey('token', $serialized);
        $this->assertSame('ci-deploy', $serialized['name']);
    }

    public function test_api_key_created_throws_on_missing_token(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ApiKeyCreated payload is missing required field "token"');

        $payload = $this->createdPayload();
        unset($payload['token']);

        ApiKeyCreated::fromArray($payload);
    }

    public function test_api_key_create_request_serializes_defaults(): void
    {
        $request = new ApiKeyCreateRequest('ci-deploy');

        $this->assertSame([
            'name' => 'ci-deploy',
            'scopes' => ['completions:write'],
            'expires_at' => '30d',
        ], $request->toArray());
    }

    public function test_api_key_create_request_serializes_never_expiry(): void
    {
        $request = new ApiKeyCreateRequest(
            name: 'long-lived',
            expiresAt: ApiKeyExpiry::Never,
        );

        $this->assertSame('never', $request->toArray()['expires_at']);
        $this->assertSame([ApiKeyScope::CompletionsWrite], $request->scopes);
    }

    public function test_api_key_round_trips_enum_values(): void
    {
        $payload = [
            'id' => 'key_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'ci-deploy',
            'scopes' => ['completions:write'],
            'expires_at' => null,
            'revoked_at' => null,
            'created_at' => '2026-08-10T00:00:00+00:00',
        ];

        $serialized = ApiKey::fromArray($payload)->toArray();

        $this->assertSame('completions:write', $serialized['scopes'][0]);
        $this->assertEquals(ApiKey::fromArray($serialized), ApiKey::fromArray($payload));
    }

    /**
     * @return array<string, mixed>
     */
    private function createdPayload(): array
    {
        return [
            'id' => 'key_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'ci-deploy',
            'scopes' => ['completions:write'],
            'expires_at' => '2026-09-09T00:00:00+00:00',
            'revoked_at' => null,
            'created_at' => '2026-08-10T00:00:00+00:00',
            'token' => 'envai_sk_01j5abcdefghjkmnpqrstvwxyz.eyJhbGciOiJFZERTQSJ9.signature',
        ];
    }
}
