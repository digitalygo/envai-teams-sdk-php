<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\ContractCompatibilityException;
use Digitalygo\Envai\Exceptions\OperationCancelledException;
use Digitalygo\Envai\Exceptions\UnsupportedCapabilityException;
use Digitalygo\Envai\Http\CancellationSource;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartFile;
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

    private function client(FakeTransport $transport, array $features = ['chat_attachments' => true, 'chat_attachment_originals' => true]): EnvaiClient
    {
        $transport->queueResponse(new HttpResponse(200, ['X-Envai-Contract-Version' => '3'], json_encode(['protocol_version' => 3, 'contract_version' => 3, 'supported_contract_versions' => [2, 3], 'legacy_v2_mode' => 'historical_reads_replay_only', 'features' => $features])));

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
                $transport->queueResponse(new HttpResponse($method === 'get' ? 200 : 202, [], json_encode(['data' => $this->resource()])));
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
            $client = $this->client($transport, $feature ? ['chat_attachments' => true, 'chat_attachment_originals' => true] : []);
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

    public function test_original_ready_attachment_returns_without_an_extra_poll(): void
    {
        $transport = new FakeTransport;
        $client = new EnvaiClient(new EnvaiConfig(baseUrl: 'https://envai.example', apiKey: new SensitiveString('synthetic')), $transport);
        $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $this->resource('original-ready')])));

        $attachment = $client->attachments()->wait('w', 'c', 'a');

        self::assertInstanceOf(ChatAttachment::class, $attachment);
        self::assertSame('ready', $attachment->lifecycle);
        self::assertSame('not_required', $attachment->conversion->status);
        self::assertNull($attachment->derived);
        self::assertSame(['GET'], array_map(fn ($request) => $request->method, $transport->requests));
    }

    public function test_not_required_attachment_waits_until_ready_and_preserves_legacy_terminal_states(): void
    {
        $transport = new FakeTransport;
        $client = new EnvaiClient(new EnvaiConfig(baseUrl: 'https://envai.example', apiKey: new SensitiveString('synthetic')), $transport);
        $notReady = $this->resource('original-ready');
        $notReady['lifecycle'] = 'uploaded';
        foreach ([$notReady, $this->resource('original-ready'), $this->resource('pending'), $this->resource('ready'), $this->resource('recoverable'), $this->resource('failed')] as $resource) {
            $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $resource])));
        }

        self::assertSame('ready', $client->attachments()->wait('w', 'c', 'a', intervalMilliseconds: 1)->lifecycle);
        self::assertSame('ready', $client->attachments()->wait('w', 'c', 'a', intervalMilliseconds: 1)->lifecycle);
        $failed = $client->attachments()->wait('w', 'c', 'a', intervalMilliseconds: 1);
        self::assertFalse($failed->conversion->recoverable);
        self::assertTrue($failed->conversion->retryable);
        self::assertSame(['GET', 'GET', 'GET', 'GET', 'GET', 'GET'], array_map(fn ($request) => $request->method, $transport->requests));
    }

    public function test_wait_preserves_withdrawn_and_cancellation_behavior(): void
    {
        $transport = new FakeTransport;
        $client = new EnvaiClient(new EnvaiConfig(baseUrl: 'https://envai.example', apiKey: new SensitiveString('synthetic')), $transport);
        $withdrawn = $this->resource('pending');
        $withdrawn['lifecycle'] = 'deleted';
        $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $withdrawn])));
        self::assertSame('deleted', $client->attachments()->wait('w', 'c', 'a')->lifecycle);

        $cancelled = new CancellationSource;
        $cancelled->cancel();
        try {
            $client->attachments()->wait('w', 'c', 'a', token: $cancelled);
            self::fail('Cancelled polling continued.');
        } catch (OperationCancelledException) {
            self::assertCount(1, $transport->requests);
        }

        $this->expectException(\InvalidArgumentException::class);
        $client->attachments()->wait('w', 'c', 'a', timeoutSeconds: 0);
    }

    public function test_native_upload_requires_both_advertised_capabilities(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'envai-attachment');
        file_put_contents($path, 'synthetic');
        try {
            foreach ([
                'missing original flag' => ['chat_attachments' => true],
                'legacy list missing original flags' => ['chat_attachments'],
                'false original flag' => ['chat_attachments' => true, 'chat_attachment_originals' => false],
                'unknown original flag' => ['chat_attachments' => true, 'chat_attachment_originals_beta' => true],
                'unknown original name in list' => ['chat_attachments', 'chat_attachment_originals_beta'],
                'non boolean original flag' => ['chat_attachments' => true, 'chat_attachment_originals' => 'true'],
                'mixed feature list' => ['chat_attachments', 'chat_attachment_originals', 42],
            ] as $features) {
                $transport = new FakeTransport;
                $client = $this->client($transport, $features);
                try {
                    $client->attachments()->upload('w', 'c', $path, 'stable-upload');
                    self::fail('Native upload passed without both capabilities.');
                } catch (UnsupportedCapabilityException) {
                    self::assertCount(1, $transport->requests, 'Only the capability preflight may reach the transport.');
                    self::assertSame('GET', $transport->requests[0]->method);
                    self::assertCount(0, array_filter($transport->requests, static fn ($request): bool => $request->method !== 'GET'));
                }
            }
        } finally {
            unlink($path);
        }
    }

    public function test_native_upload_accepts_an_advertised_feature_name_list(): void
    {
        $bytes = "listed native bytes\n";
        $path = tempnam(sys_get_temp_dir(), 'envai-attachment');
        file_put_contents($path, $bytes);
        try {
            $transport = new FakeTransport;
            $client = $this->client($transport, ['chat_attachments', 'chat_attachment_originals']);
            $transport->queueResponse(new HttpResponse(202, [], json_encode(['data' => $this->resource('original-ready')])));

            $attachment = $client->attachments()->upload('w', 'c', $path, 'listed-upload');

            self::assertSame('ready', $attachment->lifecycle);
            self::assertSame(['GET', 'POST'], array_map(static fn ($request): string => $request->method, $transport->requests));
            $request = $transport->requests[1];
            self::assertSame(['listed-upload'], $request->headers['idempotency-key']);
            self::assertSame(RetrySafety::NeverRetry, $request->retrySafety);
            self::assertInstanceOf(MultipartBody::class, $request->body);
            self::assertInstanceOf(MultipartFile::class, $request->body->parts[0]);
            self::assertSame($bytes, $request->body->parts[0]->contents());
        } finally {
            unlink($path);
        }
    }

    public function test_native_upload_also_requires_legacy_attachment_capability(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'envai-attachment');
        file_put_contents($path, 'synthetic');
        try {
            $transport = new FakeTransport;
            $client = $this->client($transport, []);
            try {
                $client->attachments()->upload('w', 'c', $path, 'stable-upload');
                self::fail('Native upload passed without chat_attachments.');
            } catch (UnsupportedCapabilityException) {
                self::assertCount(1, $transport->requests);
                self::assertSame('GET', $transport->requests[0]->method);
            }
        } finally {
            unlink($path);
        }
    }

    public function test_legacy_attachment_reads_and_deletes_do_not_require_original_capability(): void
    {
        $transport = new FakeTransport;
        $client = new EnvaiClient(new EnvaiConfig(baseUrl: 'https://envai.example', apiKey: new SensitiveString('synthetic')), $transport);
        $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $this->resource()])));
        self::assertSame('ready', $client->attachments()->get('w', 'c', 'a')->lifecycle);

        $transport->queueResponse(new HttpResponse(200, ['X-Envai-Contract-Version' => '3'], json_encode(['protocol_version' => 3, 'contract_version' => 3, 'supported_contract_versions' => [2, 3], 'legacy_v2_mode' => 'historical_reads_replay_only', 'features' => ['chat_attachments']])));
        $transport->queueResponse(new HttpResponse(202, [], json_encode(['data' => $this->resource()])));
        self::assertSame('ready', $client->attachments()->delete('w', 'c', 'a')->lifecycle);
    }

    public function test_invalid_ids_do_not_reach_transport(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChatMessageRequest('Read', ['a', 'a']);
    }
}
