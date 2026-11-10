<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Models\ApiKey\ApiKeyCreateRequest;
use Digitalygo\Envai\Models\ApiKey\ApiKeyExpiry;
use Digitalygo\Envai\Models\ApiKey\ApiKeyScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProjectTenancyTest extends TestCase
{
    /** @return iterable<string, array{bool, ?string}> */
    public static function modes(): iterable
    {
        yield 'default project key' => [false, null];
        yield 'default human audit' => [false, 'usr_01j5abcdefghjkmnpqrstvwxyz'];
        yield 'strict project key' => [true, null];
        yield 'strict human audit' => [true, 'usr_01j5abcdefghjkmnpqrstvwxyz'];
    }

    #[DataProvider('modes')]
    public function test_current_server_tenancy_hydrates_and_round_trips(bool $strict, ?string $creator): void
    {
        $transport = new FakeTransport;
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test-project-key'), strictValidation: $strict), $transport);
        $fixtures = json_decode(file_get_contents(__DIR__.'/../fixtures/project-tenancy/resources.json'), false, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $kind => $resource) {
            $payload = get_object_vars($resource);
            $payload[$kind === 'completion' ? 'user_id' : 'created_by_user_id'] = $creator;
            $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $payload], JSON_THROW_ON_ERROR)));
            $model = match ($kind) {
                'workspace' => $client->workspaces()->get($payload['id']),
                'chat' => $client->chats()->getChat($payload['workspace_id'], $payload['id']),
                'agent' => $client->agents()->get($payload['id']),
                'team' => $client->teams()->get($payload['id']),
                'completion' => $client->completions()->get($payload['workspace_id'], $payload['chat_id'], $payload['id']),
            };
            self::assertSame($payload['project_id'], $model->projectId);
            self::assertSame($creator, $kind === 'completion' ? $model->userId : $model->createdByUserId);
            self::assertSame([], $model->extra);
            self::assertEquals(json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), $model->toArray());
        }
        self::assertCount(5, $transport->requests);
    }

    public function test_tenant_identity_is_not_fabricated_from_legacy_user_id(): void
    {
        $payload = json_decode(file_get_contents(__DIR__.'/../fixtures/project-tenancy/resources.json'), true, 512, JSON_THROW_ON_ERROR)['workspace'];
        unset($payload['project_id']);
        $payload['user_id'] = 'usr_01j5abcdefghjkmnpqrstvwxyz';
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $payload], JSON_THROW_ON_ERROR)));
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test-project-key')), $transport);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('project_id');
        $client->workspaces()->get($payload['id']);
    }

    public function test_api_key_management_is_unsupported_even_with_strict_validation(): void
    {
        $transport = new FakeTransport;
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test-project-key'), strictValidation: true), $transport);
        foreach (['create', 'list', 'delete'] as $operation) {
            try {
                match ($operation) {
                    'create' => $client->apiKeys()->create(new ApiKeyCreateRequest('test', [ApiKeyScope::CompletionsWrite], ApiKeyExpiry::D30D)),
                    'list' => $client->apiKeys()->list(),
                    'delete' => $client->apiKeys()->delete('key_01j5abcdefghjkmnpqrstvwxyz'),
                };
                self::fail('Session-only API key operation must fail locally.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('human session and CSRF', $error->getMessage());
            }
        }
        self::assertSame([], $transport->requests);
        self::assertSame([], $transport->preflightRequests);
        self::assertSame([], $transport->streamRequests);
    }
}
