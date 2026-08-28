<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\Image\ImageDimensions;
use Digitalygo\Envai\Models\Image\ImageGenerationAcceptedRecord;
use Digitalygo\Envai\Models\Image\ImageGenerationLifecycleStatus;
use Digitalygo\Envai\Models\Image\ImageGenerationRequest;
use Digitalygo\Envai\Models\Image\ImageGenerationStatusRecord;
use Digitalygo\Envai\Models\Image\ImagePrice;
use Digitalygo\Envai\Models\Image\ImageUploadRecord;
use Digitalygo\Envai\Models\Image\ImageUsage;
use Digitalygo\Envai\Models\Image\InputImage;
use Digitalygo\Envai\Models\Image\InputImageType;
use Digitalygo\Envai\Models\Image\ModelCatalogEntry;
use Digitalygo\Envai\Models\Image\ModelPriceClass;
use Digitalygo\Envai\Models\Image\ModelSetting;
use Digitalygo\Envai\Models\Image\ProviderWebhookPayload;
use Digitalygo\Envai\Models\Image\TestImageFileMetadata;
use Digitalygo\Envai\Models\Image\TestImageGenerateRequest;
use Digitalygo\Envai\Models\Image\TestImageGenerateResult;
use Digitalygo\Envai\Models\Image\WebhookOk;
use Digitalygo\Envai\Models\Json\JsonObject;
use PHPUnit\Framework\TestCase;

final class ImageTest extends TestCase
{
    public function test_image_generation_request_validate_accepts_valid_payload(): void
    {
        $this->assertSame([], $this->request()->validate());
    }

    public function test_image_generation_request_validate_rejects_both_input_image_and_input_images(): void
    {
        $request = $this->request([
            'input_image' => new InputImage(InputImageType::Url, 'https://example.com/a.png'),
            'input_images' => [new InputImage(InputImageType::Url, 'https://example.com/a.png')],
        ]);

        $this->assertContains('input_image and input_images are mutually exclusive.', $request->validate());
    }

    public function test_image_generation_request_validate_rejects_mask_without_input_image(): void
    {
        $request = $this->request([
            'mask_image' => new InputImage(InputImageType::Base64, 'aGVsbG8='),
        ]);

        $this->assertContains('mask_image and objects_image require input_image.', $request->validate());
    }

    public function test_image_generation_request_validate_rejects_mask_combined_with_input_images(): void
    {
        $request = $this->request([
            'input_images' => [new InputImage(InputImageType::Url, 'https://example.com/a.png')],
            'objects_image' => new InputImage(InputImageType::Url, 'https://example.com/b.png'),
        ]);

        $violations = $request->validate();

        $this->assertContains('mask_image and objects_image cannot be combined with input_images.', $violations);
        $this->assertContains('mask_image and objects_image require input_image.', $violations);
    }

    public function test_image_generation_request_validate_rejects_http_webhook(): void
    {
        $request = $this->request(['webhook_url' => 'http://example.com/webhook']);

        $this->assertContains('webhook_url must use the https scheme.', $request->validate());
    }

    public function test_image_generation_request_validate_rejects_loopback_webhook(): void
    {
        foreach (['https://localhost/webhook', 'https://127.0.0.1/webhook', 'https://[::1]/webhook'] as $url) {
            $request = $this->request(['webhook_url' => $url]);

            $this->assertContains(
                'webhook_url must not target loopback, private, link-local or metadata addresses.',
                $request->validate(),
                sprintf('Expected %s to be rejected.', $url),
            );
        }
    }

    public function test_image_generation_request_validate_rejects_normalized_loopback_and_unspecified_literals(): void
    {
        foreach ([
            '[0:0:0:0:0:0:0:1]',
            '[::ffff:127.0.0.1]',
            '[::ffff:7f00:1]',
            '127.000.000.001',
            '[::]',
        ] as $host) {
            $request = $this->request(['webhook_url' => 'https://'.$host.'/webhook']);

            $this->assertContains(
                'webhook_url must not target loopback, private, link-local or metadata addresses.',
                $request->validate(),
                sprintf('Expected %s to be rejected.', $host),
            );
        }
    }

    public function test_image_generation_request_validate_accepts_public_ip_webhooks(): void
    {
        foreach ([
            'https://8.8.8.8/webhook',
            'https://[2606:4700:4700::1111]/webhook',
            'https://[::ffff:8.8.8.8]/webhook',
        ] as $url) {
            $request = $this->request(['webhook_url' => $url]);

            $this->assertSame([], $request->validate(), sprintf('Expected %s to pass local validation.', $url));
        }
    }

    public function test_image_generation_request_validate_rejects_private_and_metadata_ip_webhooks(): void
    {
        foreach (['https://10.0.0.5/webhook', 'https://192.168.1.10/webhook', 'https://172.16.0.1/webhook', 'https://169.254.169.254/latest/meta-data/'] as $url) {
            $request = $this->request(['webhook_url' => $url]);

            $this->assertContains(
                'webhook_url must not target loopback, private, link-local or metadata addresses.',
                $request->validate(),
            );
        }
    }

    public function test_image_generation_request_validate_rejects_eleven_input_images(): void
    {
        $images = array_map(
            static fn (int $i): InputImage => new InputImage(InputImageType::Url, sprintf('https://example.com/%d.png', $i)),
            range(1, 11),
        );

        $request = $this->request(['input_images' => $images]);

        $this->assertContains('input_images must contain at most 10 images.', $request->validate());
    }

    public function test_image_generation_request_validate_rejects_unknown_enums_and_bad_ranges(): void
    {
        $request = $this->request([
            'resolution' => '8k',
            'aspect_ratio' => '7:4',
            'format' => 'gif',
            'quality' => 101,
            'dimensions' => new ImageDimensions(0, -3),
        ]);

        $violations = $request->validate();

        $this->assertAnyViolationContains('resolution "8k" is not supported', $violations);
        $this->assertAnyViolationContains('aspect_ratio "7:4" is not supported', $violations);
        $this->assertAnyViolationContains('format "gif" is not supported', $violations);
        $this->assertContains('quality must be between 1 and 100.', $violations);
        $this->assertContains('dimensions.width must be at least 1.', $violations);
        $this->assertContains('dimensions.height must be at least 1.', $violations);
    }

    public function test_image_generation_request_validate_rejects_empty_prompt_and_empty_providers(): void
    {
        $request = $this->request(['prompt' => '   ', 'providers' => []]);

        $violations = $request->validate();

        $this->assertContains('prompt must not be empty.', $violations);
        $this->assertContains('providers must contain at least one provider.', $violations);
    }

    public function test_image_generation_request_validate_rejects_duplicate_providers(): void
    {
        $request = $this->request(['providers' => ['replicate', 'replicate']]);

        $this->assertContains('providers must not contain duplicate entries.', $request->validate());
    }

    public function test_image_generation_request_validate_rejects_hostname_with_no_host(): void
    {
        $request = $this->request(['webhook_url' => 'https://']);

        $this->assertContains('webhook_url must carry a resolvable host.', $request->validate());
    }

    public function test_image_generation_request_serializes_known_fields_only(): void
    {
        $request = $this->request([
            'input_images' => [new InputImage(InputImageType::UploadId, 'upl_01j5abcdefghjkmnpqrstvwxyz', 'style_ref')],
            'resolution' => '1k',
            'aspect_ratio' => '16:9',
            'format' => 'jpeg',
            'quality' => 90,
            'model_settings' => ['safety_tolerance' => 2, 'seed' => 42],
        ]);

        $serialized = $request->toArray();

        $this->assertSame('upl_01j5abcdefghjkmnpqrstvwxyz', $serialized['input_images'][0]['payload']);
        $this->assertSame('upload_id', $serialized['input_images'][0]['type']);
        $this->assertSame('style_ref', $serialized['input_images'][0]['reference']);
        $this->assertSame('1k', $serialized['resolution']);
        $this->assertArrayNotHasKey('dimensions', $serialized);
        $this->assertArrayNotHasKey('mask_image', $serialized);
        $this->assertArrayNotHasKey('providers', $serialized);
        $this->assertSame(['safety_tolerance' => 2, 'seed' => 42], $serialized['model_settings']);
    }

    public function test_input_image_round_trips_enum_type(): void
    {
        $image = new InputImage(InputImageType::Base64, 'aGVsbG8=', mimeType: 'image/png');

        $this->assertSame(['type' => 'base64', 'payload' => 'aGVsbG8=', 'mime_type' => 'image/png'], $image->toArray());

        $hydrated = InputImage::fromArray($image->toArray());

        $this->assertSame(InputImageType::Base64, $hydrated->type);
        $this->assertSame('aGVsbG8=', $hydrated->payload);
    }

    public function test_input_image_from_array_throws_on_unknown_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('InputImage payload field "type" has unknown value "s3"');

        InputImage::fromArray(['type' => 's3', 'payload' => 'bucket/key']);
    }

    public function test_test_image_generate_result_hydrates_files_and_preserves_extra(): void
    {
        $result = TestImageGenerateResult::fromArray([
            'status' => 'received',
            'prompt' => 'A beautiful sunset over the mountains',
            'model_code' => 'google/nano-banana-2',
            'files_count' => 2,
            'files' => [
                ['original_name' => 'photo1.jpg', 'mime_type' => 'image/jpeg', 'size' => 24576],
                ['original_name' => 'photo2.jpg', 'mime_type' => 'image/jpeg', 'size' => 10240],
            ],
            'received_at' => '2026-08-11T10:00:00+00:00',
            'echo_mode' => 'fast',
        ]);

        $this->assertSame('received', $result->status);
        $this->assertSame(2, $result->filesCount);
        $this->assertCount(2, $result->files);
        $this->assertInstanceOf(TestImageFileMetadata::class, $result->files[0]);
        $this->assertSame('photo2.jpg', $result->files[1]->originalName);
        $this->assertSame('2026-08-11T10:00:00+00:00', $result->receivedAt);
        $this->assertSame(['echo_mode' => 'fast'], $result->extra);
    }

    public function test_test_image_generate_request_serializes(): void
    {
        $request = new TestImageGenerateRequest(['/tmp/a.png', '/tmp/b.png'], 'prompt', 'google/nano-banana-2');

        $this->assertSame([
            'files' => ['/tmp/a.png', '/tmp/b.png'],
            'prompt' => 'prompt',
            'model_code' => 'google/nano-banana-2',
        ], $request->toArray());
    }

    public function test_image_generation_accepted_record_hydrates_and_preserves_extra(): void
    {
        $record = ImageGenerationAcceptedRecord::fromArray([
            'id' => 'igr_01j5abcdefghjkmnpqrstvwxyz',
            'status' => 'accepted',
            'provider' => 'replicate',
            'initial_provider' => 'fal',
            'routed' => true,
            'model' => 'google/nano-banana-2',
            'webhook_url' => 'https://example.com/webhook',
            'status_url' => 'http://localhost:8111/api/v1/images/generations/igr_01j5abcdefghjkmnpqrstvwxyz',
            'created_at' => '2026-08-11T10:00:00+00:00',
            'attempt' => 1,
        ]);

        $this->assertSame(ImageGenerationLifecycleStatus::Accepted, $record->status);
        $this->assertSame('accepted', $record->status->value);
        $this->assertTrue($record->routed);
        $this->assertSame('fal', $record->initialProvider);
        $this->assertSame(['attempt' => 1], $record->extra);
    }

    public function test_image_generation_accepted_record_round_trips_enum(): void
    {
        $payload = [
            'id' => 'igr_01j5abcdefghjkmnpqrstvwxyz',
            'status' => 'processing',
            'provider' => 'vertex',
            'initial_provider' => 'vertex',
            'routed' => false,
            'model' => 'google/nano-banana-2',
            'webhook_url' => 'https://example.com/webhook',
            'status_url' => 'https://example.com/status',
            'created_at' => '2026-08-11T10:00:00+00:00',
        ];

        $this->assertSame($payload, ImageGenerationAcceptedRecord::fromArray($payload)->toArray());
    }

    public function test_image_generation_status_record_hydrates_with_price_usage_result_and_files(): void
    {
        $record = ImageGenerationStatusRecord::fromArray([
            'id' => 'igr_01j5abcdefghjkmnpqrstvwxyz',
            'status' => 'succeeded',
            'provider' => 'replicate',
            'initial_provider' => 'replicate',
            'routed' => false,
            'model' => 'google/nano-banana-2',
            'created_at' => '2026-08-11T10:00:00+00:00',
            'submitted_at' => '2026-08-11T10:00:01+00:00',
            'completed_at' => '2026-08-11T10:00:03+00:00',
            'expires_at' => '2026-08-12T10:00:00+00:00',
            'result' => [
                'model' => 'google/nano-banana-2',
                'provider' => 'replicate',
                'images' => [
                    ['provider_asset_id' => 'asset_1', 'mime_type' => 'image/jpeg', 'source_url' => 'https://example.com/1.jpg', 'format' => 'jpeg'],
                ],
                'dimensions' => ['width' => 1024, 'height' => 1024],
                'aspect_ratio' => '1:1',
                'warnings' => [],
            ],
            'error' => null,
            'files' => [
                ['disk' => 'local', 'path' => 'igr_1/out.jpg', 'mime_type' => 'image/jpeg', 'size' => 4096, 'public_url' => 'https://example.com/out.jpg'],
            ],
            'price' => ['amount' => '0.067000', 'currency' => 'USD', 'source' => 'calculated'],
            'usage' => ['schema_version' => 1, 'attempts_total' => 1, 'attempts_priced' => 1],
            'cost_usd' => 0.067,
            'duration_ms' => 1542,
        ]);

        $this->assertSame(ImageGenerationLifecycleStatus::Succeeded, $record->status);
        $this->assertInstanceOf(JsonObject::class, $record->result);
        $this->assertSame('1:1', $record->result->get('aspect_ratio'));
        $this->assertNull($record->error);
        $this->assertCount(1, $record->files);
        $this->assertSame('local', $record->files[0]->get('disk'));
        $this->assertInstanceOf(ImagePrice::class, $record->price);
        $this->assertSame('0.067000', $record->price->amount);
        $this->assertInstanceOf(ImageUsage::class, $record->usage);
        $this->assertSame(1, $record->usage->schemaVersion);
        $this->assertSame(0.067, $record->costUsd);
        $this->assertSame(1542, $record->durationMs);
        $this->assertSame([], $record->extra);
    }

    public function test_image_generation_status_record_hydrates_queued_state_with_nulls(): void
    {
        $record = ImageGenerationStatusRecord::fromArray([
            'id' => 'igr_01j5abcdefghjkmnpqrstvwxyz',
            'status' => 'queued',
            'provider' => 'replicate',
            'initial_provider' => 'replicate',
            'routed' => false,
            'model' => 'google/nano-banana-2',
            'created_at' => '2026-08-11T10:00:00+00:00',
            'submitted_at' => null,
            'completed_at' => null,
            'expires_at' => null,
        ]);

        $this->assertSame(ImageGenerationLifecycleStatus::Queued, $record->status);
        $this->assertNull($record->submittedAt);
        $this->assertNull($record->result);
        $this->assertNull($record->price);
        $this->assertNull($record->usage);
        $this->assertNull($record->costUsd);
    }

    public function test_image_upload_record_hydrates_and_preserves_extra(): void
    {
        $record = ImageUploadRecord::fromArray([
            'id' => 'upl_01j5abcdefghjkmnpqrstvwxyz',
            'mime_type' => 'image/png',
            'size' => 24576,
            'original_filename' => 'test.png',
            'expires_at' => '2026-08-12T10:00:00+00:00',
            'created_at' => '2026-08-11T10:00:00+00:00',
            'checksum' => 'md5:abc',
        ]);

        $this->assertSame('upl_01j5abcdefghjkmnpqrstvwxyz', $record->id);
        $this->assertSame('test.png', $record->originalFilename);
        $this->assertSame(['checksum' => 'md5:abc'], $record->extra);
    }

    public function test_model_catalog_entry_hydrates_price_classes_and_settings(): void
    {
        $entry = ModelCatalogEntry::fromArray([
            'model' => 'google/nano-banana-2',
            'providers' => ['vertex', 'fal', 'replicate'],
            'price_classes' => [
                ['key' => 'resolution_1k', 'label' => '1K resolution', 'providers_count' => 3, 'average_price' => ['amount' => '0.064833', 'currency' => 'USD']],
                ['key' => 'flat', 'label' => 'Flat price', 'providers_count' => 1, 'average_price' => ['amount' => null, 'currency' => null]],
            ],
            'model_settings' => [
                ['key' => 'quality_mode', 'type' => 'string', 'providers' => ['fal'], 'default' => 'high', 'enum' => ['low', 'high']],
                ['key' => 'seed', 'type' => 'integer', 'providers' => ['vertex'], 'range' => [0, 999999]],
            ],
            'family' => 'image',
        ]);

        $this->assertSame(['vertex', 'fal', 'replicate'], $entry->providers);
        $this->assertCount(2, $entry->priceClasses);
        $this->assertInstanceOf(ModelPriceClass::class, $entry->priceClasses[0]);
        $this->assertSame('0.064833', $entry->priceClasses[0]->averagePrice->get('amount'));
        $this->assertNull($entry->priceClasses[1]->averagePrice->get('amount'));
        $this->assertCount(2, $entry->modelSettings);
        $this->assertInstanceOf(ModelSetting::class, $entry->modelSettings[0]);
        $this->assertSame('high', $entry->modelSettings[0]->default);
        $this->assertSame(['low', 'high'], $entry->modelSettings[0]->enum);
        $this->assertSame([0, 999999], $entry->modelSettings[1]->range);
        $this->assertSame(['family' => 'image'], $entry->extra);
    }

    public function test_provider_webhook_payload_passes_through(): void
    {
        $payload = ProviderWebhookPayload::fromArray(['id' => 'job-matched', 'status' => 'processing', 'custom' => ['nested' => true]]);

        $this->assertInstanceOf(JsonObject::class, $payload->payload);
        $this->assertSame('job-matched', $payload->payload->get('id'));
        $this->assertSame(['nested' => true], $payload->payload->get('custom'));
        $this->assertSame(['id' => 'job-matched', 'status' => 'processing', 'custom' => ['nested' => true]], $payload->toArray());
    }

    public function test_webhook_ok_hydrates(): void
    {
        $ok = WebhookOk::fromArray(['status' => 'ok']);

        $this->assertSame('ok', $ok->status);
        $this->assertSame(['status' => 'ok'], $ok->toArray());
    }

    /**
     * Asserts that at least one violation contains the expected substring.
     *
     * @param  list<string>  $violations
     */
    private function assertAnyViolationContains(string $expected, array $violations): void
    {
        foreach ($violations as $violation) {
            if (str_contains($violation, $expected)) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail(sprintf('Expected a violation containing "%s", got: %s', $expected, implode(' | ', $violations)));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function request(array $overrides = []): ImageGenerationRequest
    {
        $defaults = [
            'model' => 'google/nano-banana-2',
            'prompt' => 'A beautiful sunset over the mountains',
            'webhook_url' => 'https://example.com/webhook',
        ];

        $args = array_merge($defaults, $overrides);

        return new ImageGenerationRequest(
            model: $args['model'],
            prompt: $args['prompt'],
            webhookUrl: $args['webhook_url'],
            inputImages: $args['input_images'] ?? null,
            inputImage: $args['input_image'] ?? null,
            maskImage: $args['mask_image'] ?? null,
            objectsImage: $args['objects_image'] ?? null,
            resolution: $args['resolution'] ?? null,
            dimensions: $args['dimensions'] ?? null,
            aspectRatio: $args['aspect_ratio'] ?? null,
            format: $args['format'] ?? null,
            quality: $args['quality'] ?? null,
            modelSettings: $args['model_settings'] ?? null,
            providers: $args['providers'] ?? null,
        );
    }
}
