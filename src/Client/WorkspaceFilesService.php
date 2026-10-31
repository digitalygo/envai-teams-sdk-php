<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\Poller;
use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Contracts\ResponseBodySink;
use Digitalygo\Envai\Exceptions\FileException;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\DownloadResult;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartField;
use Digitalygo\Envai\Http\MultipartFile;
use Digitalygo\Envai\Http\ResourceResponseBodySink;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Wiki\WikiSource;
use Digitalygo\Envai\Models\WorkspaceFile\ConvertToPdfRequest;
use Digitalygo\Envai\Models\WorkspaceFile\WorkspaceFile;
use Digitalygo\Envai\Models\WorkspaceFile\WorkspaceFileRequest;

/**
 * Operations over the workspace fake filesystem (wiki, skills and MEMORY.md
 * are plain files under it).
 *
 * Reads are retry-safe; create, update and delete are ambiguous mutations
 * and are never retried. Downloads and PDF conversions stream through the
 * executor and are never retried either, because bytes may already have been
 * written to the sink.
 */
final readonly class WorkspaceFilesService
{
    private TypedRequester $requests;

    public function __construct(
        private RequestExecutor $executor,
        private UriResolver $uris,
        private int $connectTimeoutSeconds = 10,
        private int $requestTimeoutSeconds = 60,
    ) {
        $this->requests = new TypedRequester($executor, $uris, $connectTimeoutSeconds, $requestTimeoutSeconds);
    }

    /** Waits for an existing resource; never submits a generation or Wiki run. */
    public function waitForSource(string $workspaceId, string $fileId, float $timeoutSeconds = 300, int $intervalMilliseconds = 1000, ?CancellationToken $token = null): WikiSource
    {
        return Poller::wait(
            fn (int $remaining): WikiSource => (new self($this->executor->withoutRetries(), $this->uris, min($this->connectTimeoutSeconds, $remaining), min($this->requestTimeoutSeconds, $remaining)))->sourceStatus($workspaceId, $fileId),
            static fn (WikiSource $value): bool => $value->conversion->status === 'completed' || ($value->conversion->status === 'not_required' && in_array($value->lifecycle, ['inbox', 'raw'], true)) || ($value->conversion->status === 'failed' && ! $value->conversion->recoverable) || in_array($value->lifecycle, ['deleted', 'deleting', 'outbox'], true),
            $timeoutSeconds, $intervalMilliseconds, $token,
        );
    }

    public function uploadSource(string $workspaceId, string $localPath, string $idempotencyKey, string $filename = '', string $mimeType = 'application/octet-stream', string $processing = 'convert'): WikiSource
    {
        if (! in_array($processing, ['convert', 'original'], true)) {
            throw new \InvalidArgumentException('Source processing must be convert or original.');
        }

        $size = @filesize($localPath);
        if (! is_file($localPath) || ! is_readable($localPath) || $size === false || $size > 1000000000) {
            throw FileException::local('Source must be a readable file of at most 1,000,000,000 bytes.');
        }
        $parts = $processing === 'original' ? [new MultipartField('processing', 'original')] : [];
        $parts[] = MultipartFile::fromPath('file', $localPath, $filename, $mimeType);
        $result = $this->requests->multipart(
            'POST',
            $this->requests->resolve('/workspaces/{workspace_id}/files/sources', ['workspace_id' => $workspaceId]),
            [],
            new MultipartBody($parts),
            WikiSource::class,
            EnvelopeMode::Envelope,
            idempotencyKey: $idempotencyKey,
        );

        /** @var WikiSource $result */
        return $result;
    }

    public function sourceStatus(string $workspaceId, string $fileId): WikiSource
    {
        $result = $this->requests->request('GET', $this->requests->resolve('/workspaces/{workspace_id}/files/sources/{file_id}', ['workspace_id' => $workspaceId, 'file_id' => $fileId]), [], null, WikiSource::class, EnvelopeMode::Envelope);

        /** @var WikiSource $result */
        return $result;
    }

    public function downloadSource(string $workspaceId, string $fileId, ?ResponseBodySink $sink = null): DownloadResult
    {
        return $this->requests->stream('GET', $this->requests->resolve('/workspaces/{workspace_id}/files/sources/{file_id}/download', ['workspace_id' => $workspaceId, 'file_id' => $fileId]), [], null, $sink);
    }

    /**
     * Reads a single file (with content) or directory (with sorted entries)
     * at the given absolute workspace-relative path (GET /files?path=...).
     * The root `/` lists the top level entries.
     */
    public function readOrList(string $workspaceId, string $path = '/'): WorkspaceFile
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve('/workspaces/{workspace_id}/files', ['workspace_id' => $workspaceId]),
            ['path' => $path],
            null,
            WorkspaceFile::class,
            EnvelopeMode::Envelope,
        );

        /** @var WorkspaceFile $result */
        return $result;
    }

    /**
     * Creates a file at the given path (POST /files).
     */
    public function create(string $workspaceId, WorkspaceFileRequest $request): WorkspaceFile
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/workspaces/{workspace_id}/files', ['workspace_id' => $workspaceId]),
            [],
            $request->toArray(),
            WorkspaceFile::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var WorkspaceFile $result */
        return $result;
    }

    /**
     * Overwrites the content of an existing file (PUT /files).
     */
    public function update(string $workspaceId, WorkspaceFileRequest $request): WorkspaceFile
    {
        $result = $this->requests->request(
            'PUT',
            $this->requests->resolve('/workspaces/{workspace_id}/files', ['workspace_id' => $workspaceId]),
            [],
            $request->toArray(),
            WorkspaceFile::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var WorkspaceFile $result */
        return $result;
    }

    /**
     * Deletes a file or directory; recursive deletes non-empty directories
     * (DELETE /files?path=...&recursive=..., HTTP 204).
     */
    public function delete(string $workspaceId, string $path, bool $recursive = false): void
    {
        $this->requests->noContent(
            'DELETE',
            $this->requests->resolve('/workspaces/{workspace_id}/files', ['workspace_id' => $workspaceId]),
            ['path' => $path, 'recursive' => $recursive],
            null,
        );
    }

    /**
     * Downloads the raw bytes of a file as an attachment, streaming them
     * into the optional sink (GET /files/download?path=...). Without a sink
     * the transport buffers the body in memory up to its hard cap.
     */
    public function download(string $workspaceId, string $path, ?ResponseBodySink $sink = null): DownloadResult
    {
        return $this->requests->stream(
            'GET',
            $this->requests->resolve('/workspaces/{workspace_id}/files/download', ['workspace_id' => $workspaceId]),
            ['path' => $path],
            null,
            $sink,
        );
    }

    /**
     * Downloads a workspace file and writes it to a local destination
     * atomically-ish: the body streams into a temporary file next to the
     * destination and is renamed over it only after the transfer completed.
     * On failure the temporary file is removed and the destination is left
     * untouched.
     */
    public function saveTo(string $workspaceId, string $path, string $destination): DownloadResult
    {
        $temporary = $destination.'.part-'.bin2hex(random_bytes(8));

        $handle = @fopen($temporary, 'xb');

        if ($handle === false) {
            throw FileException::local('Unable to open the temporary download file at "'.$temporary.'".', ['path' => $temporary]);
        }

        try {
            $result = $this->download($workspaceId, $path, new ResourceResponseBodySink($handle));
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($temporary);

            throw $e;
        }

        fclose($handle);

        if (! @rename($temporary, $destination)) {
            @unlink($temporary);

            throw FileException::local('Unable to move the downloaded file into place at "'.$destination.'".', ['path' => $destination]);
        }

        return $result;
    }

    /**
     * Converts a markdown or text file to PDF and streams the document into
     * the optional sink (POST /files/convert-to-pdf). Without a sink the
     * transport buffers the body in memory up to its hard cap.
     */
    public function convertToPdf(string $workspaceId, ConvertToPdfRequest $request, ?ResponseBodySink $sink = null): DownloadResult
    {
        return $this->requests->stream(
            'POST',
            $this->requests->resolve('/workspaces/{workspace_id}/files/convert-to-pdf', ['workspace_id' => $workspaceId]),
            [],
            $request->toArray(),
            $sink,
        );
    }
}
