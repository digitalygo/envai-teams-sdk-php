<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\ConflictException;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Models\Chat\ChatUpdateRequest;
use Digitalygo\Envai\Models\Common\CleanupResult;
use PHPUnit\Framework\TestCase;

final class CleanupCrudTest extends TestCase
{
    public function test_workspace_and_chat_deletion_expose_pending_cleanup(): void
    {
        $transport = new FakeTransport;
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('key')), $transport);
        $payload = ['resource_type' => 'workspace', 'resource_id' => 'w/1', 'cleanup' => [[
            'id' => 'cln_1', 'kind' => 'workspace', 'status' => 'pending', 'attempts' => 0,
            'deadline_at' => null, 'started_at' => null, 'finished_at' => null, 'last_error_code' => null, 'future' => true,
        ]], 'new_field' => 'value'];
        $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $payload], JSON_THROW_ON_ERROR)));
        $result = $client->workspaces()->delete('w/1');
        self::assertSame($payload, $result->toArray());
        self::assertSame('pending', $result->cleanup[0]->status);
        self::assertSame('/api/v1/workspaces/w%2F1', parse_url($transport->requests[0]->uri, PHP_URL_PATH));
        self::assertSame('DELETE', $transport->requests[0]->method);
        self::assertSame(['Bearer key'], $transport->requests[0]->headers['authorization']);
        $payload = ['resource_type' => 'chat', 'resource_id' => 'c/1', 'cleanup' => []];
        $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $payload], JSON_THROW_ON_ERROR)));
        self::assertSame($payload, $client->workspace('w/1')->chats()->delete('c/1')->toArray());
        self::assertSame('/api/v1/workspaces/w%2F1/chats/c%2F1', parse_url($transport->requests[1]->uri, PHP_URL_PATH));
    }

    public function test_update_sends_only_title_and_guard_is_not_retried(): void
    {
        $transport = new FakeTransport;
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('key')), $transport);
        $chat = json_decode(file_get_contents(__DIR__.'/../fixtures/project-tenancy/resources.json'), true, 512, JSON_THROW_ON_ERROR)['chat'];
        $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $chat], JSON_THROW_ON_ERROR)));
        $client->workspace('w/1')->chats()->update('c/1', new ChatUpdateRequest(null));
        self::assertSame('PUT', $transport->requests[0]->method);
        self::assertSame(['title' => null], json_decode($transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR));
        $transport->queueResponse(new HttpResponse(409, [], '{"error":{"code":"active_cron","message":"blocked","details":{"active":true}}}'));
        try {
            $client->chats()->deleteChat('w/1', 'c/1');
            self::fail('Expected conflict');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->httpStatus);
        }
        self::assertCount(2, $transport->requests);
    }

    public function test_invalid_cleanup_entry_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CleanupResult::fromArray(['resource_type' => 'chat', 'resource_id' => 'c', 'cleanup' => [false]]);
    }
}
