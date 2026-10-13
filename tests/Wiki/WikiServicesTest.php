<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\FileException;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Wiki\CategoryIndexRequest;
use Digitalygo\Envai\Models\Wiki\WikiRunRequest;
use Digitalygo\Envai\Tests\Client\FakeTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WikiServicesTest extends TestCase
{
    private function client(FakeTransport $transport): EnvaiClient
    {
        return new EnvaiClient(new EnvaiConfig(baseUrl: 'https://envai.example', apiKey: new SensitiveString('test')), $transport);
    }

    private function response(FakeTransport $transport, string $fixture, int $status = 200): void
    {
        $transport->queueResponse(new HttpResponse($status, [], json_encode(['data' => WikiContractTest::fixture($fixture)['data']])));
    }

    public function test_all_public_services_use_scoped_paths_and_safe_retry_rules(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($transport);
        self::assertSame($client->wiki(), $client->wiki());
        foreach (['inbox' => 'inbox/runs', 'outbox' => 'outbox/runs', 'sync' => 'syncs'] as $method => $path) {
            $this->response($transport, 'operation-completed', 202);
            $operation = $client->wiki()->$method('tenant/a', new WikiRunRequest('https://hooks.example/wiki'), 'idem-'.$method);
            self::assertSame('completed', $operation->status);
            $request = end($transport->requests);
            self::assertSame('https://envai.example/api/v1/workspaces/tenant%2Fa/wiki/'.$path, $request->uri);
            self::assertSame(['idem-'.$method], $request->headers['idempotency-key']);
            self::assertSame(RetrySafety::NeverRetry, $request->retrySafety);
            self::assertSame(['webhook_url' => 'https://hooks.example/wiki'], json_decode($request->body, true));
        }
        $this->response($transport, 'operation-completed');
        $client->wiki()->operation('w', 'op/a');
        self::assertStringEndsWith('/wiki/operations/op%2Fa', end($transport->requests)->uri);
        self::assertSame(RetrySafety::Safe, end($transport->requests)->retrySafety);
        $this->response($transport, 'source-accepted');
        $client->wiki()->withdraw('w', 'file');
        self::assertSame(['file_id' => 'file'], json_decode(end($transport->requests)->body, true));
        self::assertSame(RetrySafety::NeverRetry, end($transport->requests)->retrySafety);
        $this->response($transport, 'categories');
        self::assertCount(1, $client->wiki()->categories('w')->categories);
        $this->response($transport, 'category-index');
        $index = $client->wiki()->categoryIndex('w', 'cat/a', new CategoryIndexRequest(1, 25, type: 'raw', catalogRevision: 3));
        self::assertSame(1, $index->returnedCount);
        self::assertStringEndsWith('/wiki/categories/cat%2Fa/index?from=1&to=25&type=raw&catalog_revision=3', end($transport->requests)->uri);
        self::assertSame(['all' => 'true'], (new CategoryIndexRequest(all: true))->toArray());
    }

    public function test_upload_is_lazy_multipart_and_status_download_are_distinct(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'envai-wiki');
        file_put_contents($path, 'document');
        try {
            $transport = new FakeTransport;
            $client = $this->client($transport);
            $this->response($transport, 'source-accepted', 202);
            $source = $client->workspaceFiles()->uploadSource('w', $path, 'upload-key', 'notes.md', 'text/markdown');
            self::assertSame('queued', $source->conversion->status);
            self::assertSame('pending_conversion', $source->indexing->status);
            $request = end($transport->requests);
            self::assertInstanceOf(MultipartBody::class, $request->body);
            self::assertSame($path, $request->body->parts[0]->path);
            self::assertNull($request->body->parts[0]->contents);
            self::assertSame('file', $request->body->parts[0]->name);
            self::assertSame(RetrySafety::NeverRetry, $request->retrySafety);
            self::assertSame(['upload-key'], $request->headers['idempotency-key']);
            $this->response($transport, 'source-accepted');
            $client->workspaceFiles()->sourceStatus('w', 'file/id');
            self::assertStringEndsWith('/files/sources/file%2Fid', end($transport->requests)->uri);
            $transport->queueResponse(new HttpResponse(200, ['content-type' => ['text/plain']], 'original'));
            $client->workspaceFiles()->downloadSource('w', 'file/id');
            self::assertStringEndsWith('/files/sources/file%2Fid/download', end($transport->requests)->uri);
            self::assertSame(RetrySafety::NeverRetry, end($transport->requests)->retrySafety);
        } finally {
            unlink($path);
        }
    }

    public function test_webhook_jwks_is_public_at_the_configured_origin(): void
    {
        $transport = new FakeTransport;
        $fixture = WikiContractTest::fixture('webhook-signature');
        $transport->queueResponse(new HttpResponse(200, [], json_encode($fixture['jwks'])));
        $keys = $this->client($transport)->capabilities()->wikiWebhookJwks();
        self::assertCount(1, $keys->keys);
        $request = end($transport->requests);
        self::assertSame('https://envai.example/.well-known/wiki-webhook-jwks.json', $request->uri);
        self::assertSame(AuthenticationMode::None, $request->auth);
        self::assertArrayNotHasKey('authorization', $request->headers);
    }

    public function test_invalid_upload_rejected_before_transport(): void
    {
        $transport = new FakeTransport;
        $this->expectException(FileException::class);
        $this->client($transport)->workspaceFiles()->uploadSource('w', '/does-not-exist', 'key');
    }

    public function test_upload_size_limit_is_inclusive_without_loading_bytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'envai-wiki');
        $stream = fopen($path, 'r+');
        try {
            ftruncate($stream, 1000000000);
            $transport = new FakeTransport;
            $this->response($transport, 'source-accepted', 202);
            $this->client($transport)->workspaceFiles()->uploadSource('w', $path, 'key');
            self::assertCount(1, $transport->requests);
            ftruncate($stream, 1000000001);
            clearstatcache(true, $path);
            $this->expectException(FileException::class);
            $this->client($transport)->workspaceFiles()->uploadSource('w', $path, 'key');
        } finally {
            fclose($stream);
            unlink($path);
        }
    }

    public static function invalidRequests(): iterable
    {
        yield 'range missing' => [static fn () => new CategoryIndexRequest];
        yield 'zero ordinal' => [static fn () => new CategoryIndexRequest(0, 1)];
        yield 'reversed' => [static fn () => new CategoryIndexRequest(3, 2)];
        yield 'all and range' => [static fn () => new CategoryIndexRequest(1, 2, true)];
        yield 'type' => [static fn () => new CategoryIndexRequest(all: true, type: 'source')];
        yield 'revision' => [static fn () => new CategoryIndexRequest(all: true, catalogRevision: -1)];
        yield 'http webhook' => [static fn () => new WikiRunRequest('http://example.com')];
        yield 'credentials' => [static fn () => new WikiRunRequest('https://user:secret@example.com')];
        yield 'fragment' => [static fn () => new WikiRunRequest('https://example.com/#token')];
    }

    #[DataProvider('invalidRequests')]
    public function test_invalid_request_is_rejected(\Closure $construct): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $construct();
    }

    public function test_empty_idempotency_key_is_rejected_without_a_request(): void
    {
        $transport = new FakeTransport;
        try {
            $this->client($transport)->wiki()->sync('w', new WikiRunRequest('https://hooks.example/wiki'), '');
            self::fail('Empty idempotency key accepted.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $transport->requests);
        }
    }
}
