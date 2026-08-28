<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\NotFoundException;
use Digitalygo\Envai\Exceptions\ValidationException;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\ResourceResponseBodySink;
use Digitalygo\Envai\Models\Image\ImageGenerationRequest;
use Digitalygo\Envai\Models\Image\ImageUploadRecord;
use Digitalygo\Envai\Models\Image\ProviderWebhookPayload;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Models\WorkspaceFile\ConvertToPdfRequest;
use PHPUnit\Framework\TestCase;

/**
 * Service behaviors beyond the per-operation matrix: local validation
 * fast-fail for image generation and the atomic-ish download convenience.
 */
final class ServiceBehaviorTest extends TestCase
{
    private static function client(FakeTransport $transport): EnvaiClient
    {
        $config = new EnvaiConfig(
            baseUrl: 'https://envai.example.com',
            apiKey: new SensitiveString('sk-test-secret'),
        );

        return new EnvaiClient($config, $transport);
    }

    public function test_generate_fails_fast_on_local_validation_violations(): void
    {
        $transport = new FakeTransport;
        $client = self::client($transport);

        try {
            $client->images()->generate(new ImageGenerationRequest(
                model: 'google/nano-banana-2',
                prompt: '   ',
                webhookUrl: 'http://127.0.0.1/cb',
            ));
            $this->fail('generate() must throw before any network round trip.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('validation_error', $e->apiError()?->type);
            $this->assertSame('local_validation_failed', $e->apiError()?->code);
            $this->assertIsArray($e->apiError()?->details);
            $this->assertNotEmpty($e->apiError()?->details);
        }

        $this->assertCount(0, $transport->requests, 'No request may leave the client when local validation fails.');
    }

    public function test_generate_passes_through_when_local_validation_succeeds(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(202, ['content-type' => ['application/json']], json_encode([
            'data' => [
                'id' => 'igr_01j5abcdefghjkmnpqrstvwxyz',
                'status' => 'accepted',
                'provider' => 'replicate',
                'initial_provider' => 'replicate',
                'routed' => false,
                'model' => 'google/nano-banana-2',
                'webhook_url' => 'https://hooks.example.com/cb',
                'status_url' => 'https://envai.example.com/api/v1/images/generations/igr_01j5abcdefghjkmnpqrstvwxyz',
                'created_at' => '2026-08-28T10:00:00+00:00',
            ],
        ], JSON_THROW_ON_ERROR)));

        $client = self::client($transport);

        $record = $client->images()->generate(new ImageGenerationRequest(
            model: 'google/nano-banana-2',
            prompt: 'A beautiful sunset',
            webhookUrl: 'https://hooks.example.com/cb',
        ));

        $this->assertSame('accepted', $record->status->value);
        $this->assertCount(1, $transport->requests);
    }

    public function test_upload_from_contents_sends_a_multipart_file(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(201, ['content-type' => ['application/json']], json_encode([
            'data' => [
                'id' => 'upl_01j5abcdefghjkmnpqrstvwxyz',
                'mime_type' => 'image/png',
                'size' => 4096,
                'original_filename' => 'photo.png',
                'expires_at' => '2026-08-29T10:00:00+00:00',
                'created_at' => '2026-08-28T10:00:00+00:00',
            ],
        ], JSON_THROW_ON_ERROR)));

        $client = self::client($transport);

        $record = $client->images()->uploadContents('png-bytes', 'photo.png', 'image/png');

        $this->assertInstanceOf(ImageUploadRecord::class, $record);
        $this->assertSame('photo.png', $record->originalFilename);
        $this->assertSame('/api/v1/images/uploads', parse_url($transport->requests[0]->uri, PHP_URL_PATH));
    }

    public function test_convert_to_pdf_with_sink_streams_through(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(200, [
            'content-type' => ['application/pdf'],
            'content-disposition' => ['attachment; filename="guide.pdf"'],
            'content-length' => ['4096'],
        ]));

        $client = self::client($transport);
        $sink = new ResourceResponseBodySink(fopen('php://memory', 'wb'));

        $result = $client->workspaceFiles()->convertToPdf('wsp_01j5abcdefghjkmnpqrstvwxyz', new ConvertToPdfRequest('/wiki/guide.md'), $sink);

        $this->assertSame('application/pdf', $result->mediaType);
        $this->assertSame($sink, $transport->requests[0]->sink, 'The download sink must be attached to the streaming request.');
    }

    public function test_save_to_writes_temporarily_and_renames(): void
    {
        $directory = sys_get_temp_dir().'/envai-save-to-'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            $transport = new FakeTransport;
            $transport->queueResponse(new HttpResponse(200, [
                'content-type' => ['text/markdown; charset=utf-8'],
                'content-disposition' => ['attachment; filename="guide.md"'],
                'content-length' => ['2048'],
            ]));

            $client = self::client($transport);
            $destination = $directory.'/guide.md';

            $result = $client->workspaceFiles()->saveTo('wsp_01j5abcdefghjkmnpqrstvwxyz', '/wiki/guide.md', $destination);

            $this->assertSame('text/markdown', $result->mediaType);
            $this->assertFileExists($destination);

            $parts = glob($destination.'.part-*') ?: [];
            $this->assertSame([], $parts, 'The temporary download file must be removed after the rename.');
        } finally {
            array_map('unlink', glob($directory.'/*') ?: []);
            @rmdir($directory);
        }
    }

    public function test_save_to_cleans_the_temp_file_on_failure(): void
    {
        $directory = sys_get_temp_dir().'/envai-save-to-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $destination = $directory.'/guide.md';

        try {
            $transport = new FakeTransport;
            $transport->queueResponse(new HttpResponse(404, ['content-type' => ['application/json']], json_encode([
                'error' => ['type' => 'not_found', 'message' => 'File not found.', 'details' => null],
            ], JSON_THROW_ON_ERROR)));

            $client = self::client($transport);

            $this->expectException(NotFoundException::class);
            $client->workspaceFiles()->saveTo('wsp_01j5abcdefghjkmnpqrstvwxyz', '/wiki/missing.md', $destination);
        } finally {
            $this->assertFileDoesNotExist($destination);
            $parts = glob($directory.'/*') ?: [];
            $this->assertSame([], $parts, 'No temporary or destination file may survive a failed download.');
            array_map('unlink', glob($directory.'/*') ?: []);
            @rmdir($directory);
        }
    }

    public function test_read_or_list_defaults_to_the_root_listing(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(200, ['content-type' => ['application/json']], json_encode([
            'data' => [
                'path' => 'wiki',
                'name' => 'wiki',
                'type' => 'directory',
                'size' => 0,
                'modified_at' => null,
                'mime_type' => null,
                'entries' => [['path' => 'wiki', 'name' => 'wiki', 'type' => 'directory', 'size' => 0, 'modified_at' => null, 'mime_type' => null]],
            ],
        ], JSON_THROW_ON_ERROR)));

        $client = self::client($transport);
        $file = $client->workspaceFiles()->readOrList('wsp_01j5abcdefghjkmnpqrstvwxyz');

        $this->assertSame('directory', $file->type->value);
        $this->assertCount(1, $file->entries);
        $this->assertStringContainsString('path=%2F', $transport->requests[0]->uri, 'The root listing must send path=/ encoded.');
    }

    public function test_provider_webhook_payload_is_passed_verbatim(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(200, ['content-type' => ['application/json']], json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR)));

        $client = self::client($transport);

        $payload = new ProviderWebhookPayload(new JsonObject(['id' => 'job-1', 'nested' => ['a' => 1]]));
        $ok = $client->images()->submitProviderWebhook('fal', $payload);

        $this->assertSame('ok', $ok->status);
        $this->assertSame(['id' => 'job-1', 'nested' => ['a' => 1]], json_decode((string) $transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('authorization', $transport->requests[0]->headers);
    }
}
