<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\ContractCompatibilityException;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Chat\ChatAttachment;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use PHPUnit\Framework\TestCase;

final class ChatAttachmentsTest extends TestCase
{
    private function resource(string $name = 'ready'): array
    {
        return json_decode(file_get_contents(dirname(__DIR__).'/fixtures/chat-attachments/'.$name.'.json'), true)['data'];
    }

    private function client(FakeTransport $transport, bool $feature = true): EnvaiClient
    {
        $transport->queueResponse(new HttpResponse(200, ['X-Envai-Contract-Version' => '3'], json_encode(['protocol_version' => 3, 'contract_version' => 3, 'supported_contract_versions' => [2, 3], 'legacy_v2_mode' => 'historical_reads_replay_only', 'features' => $feature ? ['chat_attachments'] : []])));

        return new EnvaiClient(new EnvaiConfig(baseUrl: 'https://envai.example', apiKey: new SensitiveString('synthetic')), $transport);
    }

    public function test_services_preserve_private_scope_and_upload_without_buffering(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($transport);
        $path = tempnam(sys_get_temp_dir(), 'envai-attachment');
        file_put_contents($path, 'synthetic');
        try {
            self::assertSame($client->attachments(), $client->attachments());
            $transport->queueResponse(new HttpResponse(202, [], json_encode(['data' => $this->resource()])));
            $source = $client->attachments()->upload('w/a', 'c/b', $path, 'upload');
            self::assertInstanceOf(ChatAttachment::class, $source);
            self::assertSame($this->resource(), $source->toArray());
            $request = end($transport->requests);
            self::assertInstanceOf(MultipartBody::class, $request->body);
            self::assertSame($path, $request->body->parts[0]->path);
            self::assertSame(['upload'], $request->headers['idempotency-key']);
            self::assertSame(RetrySafety::NeverRetry, $request->retrySafety);
            self::assertStringEndsWith('/workspaces/w%2Fa/chats/c%2Fb/attachments', $request->uri);
            foreach (['get', 'retry', 'delete'] as $method) {
                $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $this->resource()])));
                $source = $method === 'retry' ? $client->attachments()->retry('w/a', 'c/b', 'a', 'retry') : $client->attachments()->$method('w/a', 'c/b', 'a');
                self::assertSame('ready', $source->lifecycle);
            }
            $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => ['attachments' => [$this->resource()]]])));
            self::assertCount(1, $client->attachments()->all('w/a', 'c/b'));
            $transport->queueResponse(new HttpResponse(200, [], 'original'));
            $client->attachments()->download('w/a', 'c/b', 'a', 'original');
            self::assertStringEndsWith('/attachments/a/download?kind=original', end($transport->requests)->uri);
        } finally {
            unlink($path);
        }
    }

    public function test_message_requires_capability_key_and_matching_acknowledgment(): void
    {
        $request = new ChatMessageRequest('Read', ['a']);
        self::assertSame(['message' => 'Read', 'attachment_ids' => ['a']], $request->toArray());
        foreach ([false, true] as $feature) {
            $transport = new FakeTransport;
            $client = $this->client($transport, $feature);
            $transport->queueResponse(new HttpResponse(202, [], json_encode(['status' => 'accepted', 'turn_id' => 'cmpl_test', 'attachment_ids' => ['different']])));
            try {
                $client->chats()->sendMessage('c', $request, 'stable');
                self::fail('Unsafe attachment acceptance');
            } catch (ContractCompatibilityException) {
                self::assertCount($feature ? 2 : 1, $transport->requests);
            }
        }
        $transport = new FakeTransport;
        $client = $this->client($transport);
        $transport->queueResponse(new HttpResponse(202, [], json_encode(['status' => 'accepted', 'turn_id' => 'cmpl_test', 'attachment_ids' => ['a']])));
        self::assertSame('cmpl_test', $client->chat('w', 'c')->sendMessage('Read', ['a'], 'stable')->turnId);
        self::assertSame(['stable'], end($transport->requests)->headers['idempotency-key']);
        $this->expectException(\InvalidArgumentException::class);
        $client->chats()->sendMessage('c', $request);
    }

    public function test_polling_reuses_converted_resources_and_returns_failed_state(): void
    {
        $transport = new FakeTransport;
        $client = new EnvaiClient(new EnvaiConfig(baseUrl: 'https://envai.example', apiKey: new SensitiveString('synthetic')), $transport);
        foreach (['pending', 'ready', 'failed'] as $name) {
            $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $this->resource($name)])));
        }
        self::assertSame('ready', $client->attachments()->wait('w', 'c', 'a', intervalMilliseconds: 1)->lifecycle);
        self::assertTrue($client->attachments()->wait('w', 'c', 'a')->conversion->retryable);
        self::assertSame(['GET', 'GET', 'GET'], array_map(fn ($r) => $r->method, $transport->requests));
        $this->expectException(\InvalidArgumentException::class);
        $client->attachments()->wait('w', 'c', 'a', timeoutSeconds: 0);
    }

    public function test_invalid_ids_do_not_reach_transport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChatMessageRequest('Read', ['a', 'a']);
    }
}
