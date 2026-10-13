<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use Digitalygo\Envai\Models\Image\ImageGenerationAcceptedRecord;
use Digitalygo\Envai\Models\Image\ImageGenerationStatusRecord;
use Digitalygo\Envai\Models\Model\LlmModelEntry;
use PHPUnit\Framework\TestCase;

final class CurrentServerCompatibilityTest extends TestCase
{
    private static function client(FakeTransport $transport): EnvaiClient
    {
        return new EnvaiClient(new EnvaiConfig(baseUrl: 'https://envai.example.com/api/v1', apiKey: new SensitiveString('test-only')), $transport);
    }

    public function test_opaque_artifact_download_preserves_laravel_signed_query_order_and_omits_auth(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(200, ['content-type' => ['image/png']], 'bytes'));
        self::client($transport)->images()->downloadGenerationArtifact('igr_example', 'art_example', 'signed', 1786320000);
        $request = $transport->requests[0];
        $this->assertSame('https://envai.example.com/api/v1/artifacts/download?artifact=art_example&expires=1786320000&generation=igr_example&signature=signed', $request->uri);
        $this->assertArrayNotHasKey('authorization', $request->headers);
        $this->assertSame(RetrySafety::NeverRetry, $request->retrySafety);
    }

    public function test_deprecated_path_download_remains_callable_for_older_servers(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(200, [], 'bytes'));
        self::client($transport)->images()->downloadArtifact('generated/legacy.png', 'signed');
        parse_str(parse_url($transport->requests[0]->uri, PHP_URL_QUERY), $query);
        $this->assertSame('generated/legacy.png', $query['path']);
        $this->assertArrayNotHasKey('authorization', $transport->requests[0]->headers);
    }

    public function test_opaque_artifact_download_without_expiry(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(200, [], 'bytes'));
        self::client($transport)->images()->downloadGenerationArtifact('igr_example', 'art_example', 'signed');
        $this->assertSame('artifact=art_example&generation=igr_example&signature=signed', parse_url($transport->requests[0]->uri, PHP_URL_QUERY));
    }

    public function test_gateway_draining_is_reported_without_retrying_message_submission(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(503, [], '{"error":{"type":"server_error","code":"gateway_draining","message":"Draining","details":null}}'));
        try {
            self::client($transport)->chats()->sendMessage('cht_example', new ChatMessageRequest('hello'));
            $this->fail('Expected gateway_draining error.');
        } catch (ServerException $exception) {
            $this->assertSame(503, $exception->httpStatus);
            $this->assertSame('gateway_draining', $exception->apiError()->code);
            $this->assertCount(1, $transport->requests);
        }
    }

    public function test_current_generation_expiry_and_opaque_file_metadata_are_preserved(): void
    {
        $base = ['id' => 'igr_example', 'status' => 'accepted', 'provider' => 'replicate', 'initial_provider' => 'replicate', 'routed' => false, 'model' => 'fixture/model', 'created_at' => '2026-10-12T20:00:00Z', 'expires_at' => '2026-10-12T22:00:00Z'];
        $accepted = ImageGenerationAcceptedRecord::fromArray($base + ['webhook_url' => 'https://example.com/webhook', 'status_url' => '/api/v1/images/generations/igr_example']);
        $this->assertSame($base['expires_at'], $accepted->expiresAt);
        $this->assertSame($base['expires_at'], $accepted->toArray()['expires_at']);
        $files = [['artifact_id' => 'art_example', 'mime_type' => 'image/png', 'size' => 128, 'download_url' => '/api/v1/artifacts/download?artifact=art_example&generation=igr_example&signature=signed']];
        $status = ImageGenerationStatusRecord::fromArray($base + ['files' => $files]);
        $this->assertSame('art_example', $status->files[0]->get('artifact_id'));
        $this->assertSame($files, $status->toArray()['files']);
    }

    public function test_upstream_model_metadata_preserves_exact_pricing_strings_and_unknown_fields(): void
    {
        $wire = [
            'id' => 'fixture/model', 'object' => 'model', 'created' => 1, 'owned_by' => 'fixture', 'type' => 'llm', 'deprecated' => false, 'sunset_at' => null,
            'display_name' => 'Fixture model', 'context_length' => 200000, 'max_completion_tokens' => 8192,
            'pricing' => ['prompt' => '0.0000001234567890123456789', 'nested' => ['override' => '0.0001']],
            'architecture' => ['input_modalities' => ['text', 'image']], 'supported_parameters' => ['temperature'],
            'default_parameters' => ['temperature' => 0.7], 'reasoning' => ['enabled' => true],
            'alias_target' => ['name' => 'Target', 'slug' => 'fixture/target'], 'is_moderated' => false,
            'knowledge_cutoff' => '2024-06-30', 'upstream_expiration_date' => '2027-01-01', 'synced_at' => '2026-10-12T20:00:00Z', 'future_key' => true,
        ];
        $entry = LlmModelEntry::fromArray($wire);
        $this->assertSame($wire['pricing']['prompt'], $entry->pricing->get('prompt'));
        $this->assertSame(200000, $entry->contextLength);
        $this->assertSame(['temperature'], $entry->supportedParameters);
        $this->assertFalse($entry->isModerated);
        $this->assertSame(['future_key' => true], $entry->extra);
        $this->assertEquals($wire, $entry->toArray());
    }
}
