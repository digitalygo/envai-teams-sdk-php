<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Contracts\ResponseBodySink;
use Digitalygo\Envai\Exceptions\ValidationException;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\DownloadResult;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartField;
use Digitalygo\Envai\Http\MultipartFile;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Common\ApiError;
use Digitalygo\Envai\Models\Common\ResourceCollection;
use Digitalygo\Envai\Models\Image\ImageGenerationAcceptedRecord;
use Digitalygo\Envai\Models\Image\ImageGenerationRequest;
use Digitalygo\Envai\Models\Image\ImageGenerationStatusRecord;
use Digitalygo\Envai\Models\Image\ImageUploadRecord;
use Digitalygo\Envai\Models\Image\ModelCatalogEntry;
use Digitalygo\Envai\Models\Image\ProviderWebhookPayload;
use Digitalygo\Envai\Models\Image\TestImageGenerateRequest;
use Digitalygo\Envai\Models\Image\TestImageGenerateResult;
use Digitalygo\Envai\Models\Image\WebhookOk;

/**
 * Asynchronous image generation, uploads, the image model catalog, signed
 * artifact downloads and provider webhooks.
 *
 * generate() runs the request's local fast-fail validation first and throws
 * a ValidationException with a synthetic ApiError when violations exist, so
 * obviously invalid requests never hit the network. Server validation stays
 * authoritative. Mutations and streaming downloads are never retried; the
 * signed artifact URL, the local test endpoint and the provider webhook are
 * public and carry no Authorization header.
 */
final readonly class ImagesService
{
    private TypedRequester $requests;

    public function __construct(
        RequestExecutor $executor,
        UriResolver $uris,
        int $connectTimeoutSeconds = 10,
        int $requestTimeoutSeconds = 60,
    ) {
        $this->requests = new TypedRequester($executor, $uris, $connectTimeoutSeconds, $requestTimeoutSeconds);
    }

    /**
     * Creates an asynchronous image generation (POST /images/generations,
     * HTTP 202). The request is validated locally first; on violations a
     * ValidationException is thrown before any network round trip.
     */
    public function generate(ImageGenerationRequest $request): ImageGenerationAcceptedRecord
    {
        $violations = $request->validate();

        if ($violations !== []) {
            throw new ValidationException(
                'The image generation request failed local validation.',
                422,
                new ApiError(
                    'validation_error',
                    'local_validation_failed',
                    'The image generation request failed local validation.',
                    $violations,
                ),
            );
        }

        $result = $this->requests->request(
            'POST',
            '/images/generations',
            [],
            $request->toArray(),
            ImageGenerationAcceptedRecord::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var ImageGenerationAcceptedRecord $result */
        return $result;
    }

    /**
     * Returns the current lifecycle status of a generation (GET
     * /images/generations/{record}).
     */
    public function getGeneration(string $record): ImageGenerationStatusRecord
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve('/images/generations/{record}', ['record' => $record]),
            [],
            null,
            ImageGenerationStatusRecord::class,
            EnvelopeMode::Envelope,
        );

        /** @var ImageGenerationStatusRecord $result */
        return $result;
    }

    /**
     * Uploads a single image from a local path for later use through
     * upload_id (POST /images/uploads, HTTP 201, multipart field `file`).
     */
    public function upload(string $path, string $mimeType = 'application/octet-stream'): ImageUploadRecord
    {
        $body = new MultipartBody([
            MultipartFile::fromPath('file', $path, mimeType: $mimeType),
        ]);

        $result = $this->requests->multipart(
            'POST',
            '/images/uploads',
            [],
            $body,
            ImageUploadRecord::class,
            EnvelopeMode::Envelope,
        );

        /** @var ImageUploadRecord $result */
        return $result;
    }

    /**
     * Uploads a single image from in-memory contents (POST /images/uploads,
     * HTTP 201, multipart field `file`).
     */
    public function uploadContents(string $contents, string $filename, string $mimeType = 'application/octet-stream'): ImageUploadRecord
    {
        $body = new MultipartBody([
            MultipartFile::fromContents('file', $contents, $filename, $mimeType),
        ]);

        $result = $this->requests->multipart(
            'POST',
            '/images/uploads',
            [],
            $body,
            ImageUploadRecord::class,
            EnvelopeMode::Envelope,
        );

        /** @var ImageUploadRecord $result */
        return $result;
    }

    /**
     * Lists the image model catalog grouped by canonical model (GET
     * /images/models).
     *
     * @return ResourceCollection<ModelCatalogEntry>
     */
    public function listImageModels(): ResourceCollection
    {
        $result = $this->requests->request(
            'GET',
            '/images/models',
            [],
            null,
            ModelCatalogEntry::class,
            EnvelopeMode::Collection,
        );

        /** @var ResourceCollection<ModelCatalogEntry> $result */
        return $result;
    }

    /**
     * Legacy path-based artifact download for older servers only.
     *
     * @deprecated Use downloadGenerationArtifact() with opaque generation and artifact IDs.
     *
     * Downloads a generated artifact through a relative signed URL (GET
     * /artifacts/download?path=...&signature=...&expires=...). Public
     * endpoint: no Authorization header is sent. The body streams into the
     * optional sink; without one the transport buffers up to its hard cap.
     */
    public function downloadArtifact(string $path, string $signature, ?int $expires = null, ?ResponseBodySink $sink = null): DownloadResult
    {
        $query = ['path' => $path, 'signature' => $signature];

        if ($expires !== null) {
            $query['expires'] = $expires;
        }

        return $this->requests->stream(
            'GET',
            '/artifacts/download',
            $query,
            null,
            $sink,
            AuthenticationMode::None,
        );
    }

    /**
     * Downloads a current-server artifact by the opaque identifiers and
     * signature supplied in its download_url. No bearer token is sent.
     * Expiry must be copied from the signed URL, never recomputed locally.
     */
    public function downloadGenerationArtifact(string $generation, string $artifact, string $signature, ?int $expires = null, ?ResponseBodySink $sink = null): DownloadResult
    {
        $query = ['generation' => $generation, 'artifact' => $artifact];

        if ($expires !== null) {
            $query['expires'] = $expires;
        }

        // Laravel validates the original query ordering: signature is last,
        // after the alphabetically sorted signed generation/artifact fields.
        ksort($query);
        $query['signature'] = $signature;

        return $this->requests->stream(
            'GET',
            '/artifacts/download',
            $query,
            null,
            $sink,
            AuthenticationMode::None,
        );
    }

    /**
     * Echo endpoint for image generation contract validation (POST
     * /tests/images/generate). Local and testing only, public, multipart
     * fields `files[]`, `prompt` and `model_code`.
     */
    public function testGenerateImage(TestImageGenerateRequest $request): TestImageGenerateResult
    {
        $parts = [
            new MultipartField('prompt', $request->prompt),
            new MultipartField('model_code', $request->modelCode),
        ];

        foreach ($request->files as $file) {
            $parts[] = MultipartFile::fromPath('files[]', $file);
        }

        $result = $this->requests->multipart(
            'POST',
            '/tests/images/generate',
            [],
            new MultipartBody($parts),
            TestImageGenerateResult::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::None,
        );

        /** @var TestImageGenerateResult $result */
        return $result;
    }

    /**
     * Forwards a provider webhook body (POST
     * /webhooks/providers/{provider}?callback_token=...). Public endpoint,
     * raw provider owned JSON body, acknowledged with {status: ok}.
     */
    public function submitProviderWebhook(string $provider, ProviderWebhookPayload $payload, ?string $callbackToken = null): WebhookOk
    {
        $query = $callbackToken === null ? [] : ['callback_token' => $callbackToken];

        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/webhooks/providers/{provider}', ['provider' => $provider]),
            $query,
            $payload->toArray(),
            WebhookOk::class,
            EnvelopeMode::TopLevel,
            AuthenticationMode::None,
            RetrySafety::NeverRetry,
        );

        /** @var WebhookOk $result */
        return $result;
    }
}
