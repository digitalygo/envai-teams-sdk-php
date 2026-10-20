<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartField;
use Digitalygo\Envai\Http\MultipartFile;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\System\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the canonical contract-3 capabilities response.
 *
 * The Go execution plane advertises optional features as a positive list of
 * strings, including chat_attachment_originals and wiki_original_sources.
 * The SDK must accept those exact advertised names instead of requiring a
 * boolean feature map.
 */
final class CanonicalCapabilitiesInteropTest extends TestCase
{
    /** @return array{headers: array<string, list<string>>, body: array<string, mixed>} */
    private function capabilities(): array
    {
        /** @var array{headers: array<string, list<string>>, body: array<string, mixed>} $fixture */
        $fixture = json_decode(
            file_get_contents(dirname(__DIR__).'/fixtures/capabilities/canonical-v3.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $fixture;
    }

    private function client(FakeTransport $transport): EnvaiClient
    {
        $fixture = $this->capabilities();
        $transport->queueResponse(new HttpResponse(
            200,
            $fixture['headers'],
            json_encode($fixture['body'], JSON_THROW_ON_ERROR),
        ));

        return new EnvaiClient(new EnvaiConfig(baseUrl: 'https://envai.example', apiKey: new SensitiveString('synthetic')), $transport);
    }

    /** @return array<string, mixed> */
    private function chatAttachment(): array
    {
        /** @var array{data: array<string, mixed>} $fixture */
        $fixture = json_decode(
            file_get_contents(dirname(__DIR__).'/fixtures/chat-attachments/original-ready.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $fixture['data'];
    }

    /** @return array<string, mixed> */
    private function wikiSource(): array
    {
        /** @var array{data: array<string, mixed>} $fixture */
        $fixture = json_decode(
            file_get_contents(dirname(__DIR__).'/fixtures/wiki/source-accepted.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $fixture['data']['conversion']['status'] = 'not_required';

        return $fixture['data'];
    }

    public function test_canonical_feature_list_allows_native_chat_upload_after_positive_preflight(): void
    {
        $bytes = "canonical chat attachment\0bytes\n";
        $path = tempnam(sys_get_temp_dir(), 'envai-canonical-attachment');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        try {
            $transport = new FakeTransport;
            $client = $this->client($transport);
            $transport->queueResponse(new HttpResponse(202, [], json_encode(['data' => $this->chatAttachment()], JSON_THROW_ON_ERROR)));

            $attachment = $client->attachments()->upload('wsp_canonical', 'cht_canonical', $path, 'canonical-native-key', 'application/octet-stream');

            self::assertSame($this->chatAttachment()['attachment_id'], $attachment->attachmentId);
            self::assertSame(['GET', 'POST'], array_map(static fn ($request): string => $request->method, $transport->requests));

            $preflight = $transport->requests[0];
            self::assertSame('GET', $preflight->method);
            self::assertSame('/api/v1/atp/v3/capabilities', parse_url($preflight->uri, PHP_URL_PATH));
            self::assertSame(['3'], $preflight->headers['x-envai-contract-version']);

            $upload = $transport->requests[1];
            self::assertSame('POST', $upload->method);
            self::assertStringEndsWith('/workspaces/wsp_canonical/chats/cht_canonical/attachments', $upload->uri);
            self::assertSame(['canonical-native-key'], $upload->headers['idempotency-key']);
            self::assertSame(RetrySafety::NeverRetry, $upload->retrySafety);
            self::assertInstanceOf(MultipartBody::class, $upload->body);
            self::assertCount(1, $upload->body->parts);
            self::assertInstanceOf(MultipartFile::class, $upload->body->parts[0]);
            self::assertSame($path, $upload->body->parts[0]->path);
            self::assertSame($bytes, $upload->body->parts[0]->contents());
            self::assertSame(hash('sha256', $bytes), hash('sha256', $upload->body->parts[0]->contents()));
        } finally {
            unlink($path);
        }
    }

    public function test_canonical_feature_list_allows_original_wiki_upload_after_positive_preflight(): void
    {
        $bytes = "canonical original wiki source\0bytes\n";
        $path = tempnam(sys_get_temp_dir(), 'envai-canonical-wiki');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        try {
            $transport = new FakeTransport;
            $client = $this->client($transport);
            $transport->queueResponse(new HttpResponse(202, [], json_encode(['data' => $this->wikiSource()], JSON_THROW_ON_ERROR)));

            $source = $client->workspaceFiles()->uploadSource('wsp_canonical', $path, 'canonical-wiki-key', 'source.txt', 'text/plain', 'original');

            self::assertSame('not_required', $source->conversion->status);
            self::assertSame(['GET', 'POST'], array_map(static fn ($request): string => $request->method, $transport->requests));

            $preflight = $transport->requests[0];
            self::assertSame('GET', $preflight->method);
            self::assertSame('/api/v1/atp/v3/capabilities', parse_url($preflight->uri, PHP_URL_PATH));
            self::assertSame(['3'], $preflight->headers['x-envai-contract-version']);

            $upload = $transport->requests[1];
            self::assertSame('POST', $upload->method);
            self::assertStringEndsWith('/workspaces/wsp_canonical/files/sources', $upload->uri);
            self::assertSame(['canonical-wiki-key'], $upload->headers['idempotency-key']);
            self::assertSame(RetrySafety::NeverRetry, $upload->retrySafety);
            self::assertInstanceOf(MultipartBody::class, $upload->body);
            self::assertCount(2, $upload->body->parts);
            self::assertInstanceOf(MultipartField::class, $upload->body->parts[0]);
            self::assertSame('processing', $upload->body->parts[0]->name);
            self::assertSame('original', $upload->body->parts[0]->value);
            self::assertInstanceOf(MultipartFile::class, $upload->body->parts[1]);
            self::assertSame($path, $upload->body->parts[1]->path);
            self::assertSame($bytes, $upload->body->parts[1]->contents());
            self::assertSame(hash('sha256', $bytes), hash('sha256', $upload->body->parts[1]->contents()));
        } finally {
            unlink($path);
        }
    }

    public function test_canonical_capability_fixture_matches_its_provenance(): void
    {
        $directory = dirname(__DIR__).'/fixtures/capabilities/';
        /** @var array{source_repository: string, source_path: string, files: array<string, string>} $provenance */
        $provenance = json_decode(file_get_contents($directory.'provenance.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('digitalygo/envai-teams', $provenance['source_repository']);
        self::assertSame('apps/execution-plane/internal/gateway/capabilities.go', $provenance['source_path']);
        self::assertSame($provenance['files']['canonical-v3.json'], hash_file('sha256', $directory.'canonical-v3.json'));
    }

    public function test_canonical_capabilities_hydrate_with_the_positive_feature_list(): void
    {
        $body = $this->capabilities()['body'];
        $capabilities = Capabilities::fromArray($body);

        self::assertSame(3, $capabilities->protocolVersion);
        self::assertSame([2, 3], $capabilities->supportedContractVersions);
        self::assertSame([
            'replay',
            'fork',
            'restore',
            'ping',
            'background_delegation',
            'client_tools',
            'chat_title',
            'chat_attachments',
            'chat_attachment_originals',
            'wiki_original_sources',
        ], $capabilities->features);
        self::assertEquals($body, $capabilities->toArray());
    }
}
