<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Contracts\ResponseBodySink;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\DownloadResult;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartFile;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Chat\ChatAttachment;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class ChatAttachmentsService
{
    private TypedRequester $requests;

    public function __construct(private RequestExecutor $executor, private UriResolver $uris, private int $connectTimeoutSeconds = 10, private int $requestTimeoutSeconds = 60)
    {
        $this->requests = new TypedRequester($executor, $uris, $connectTimeoutSeconds, $requestTimeoutSeconds);
    }

    public function upload(string $workspaceId, string $chatId, string $path, string $idempotencyKey, string $mimeType = 'application/octet-stream'): ChatAttachment
    {
        $size = is_file($path) ? filesize($path) : false;
        if ($size === false || $size > 1_000_000_000) {
            throw new \InvalidArgumentException('Attachment must be a regular file of at most 1,000,000,000 bytes.');
        }
        $result = $this->requests->multipart('POST', $this->path($workspaceId, $chatId), [], new MultipartBody([MultipartFile::fromPath('file', $path, mimeType: $mimeType)]), ChatAttachment::class, EnvelopeMode::Envelope, retry: RetrySafety::NeverRetry, idempotencyKey: $idempotencyKey);
        assert($result instanceof ChatAttachment);

        return $result;
    }

    public function get(string $workspaceId, string $chatId, string $attachmentId): ChatAttachment
    {
        return $this->resource('GET', $workspaceId, $chatId, $attachmentId);
    }

    /** @return list<ChatAttachment> */
    public function all(string $workspaceId, string $chatId): array
    {
        $payload = $this->requests->payload('GET', $this->path($workspaceId, $chatId), [], null);
        $data = Wire::objectArray($payload, 'data', self::class);
        $attachments = $data['attachments'] ?? null;
        if (! is_array($attachments) || ! array_is_list($attachments)) {
            throw new \InvalidArgumentException('Attachment collection must be a list.');
        }

        return array_map(static function (mixed $item): ChatAttachment {
            if (! is_array($item) || count(array_filter(array_keys($item), 'is_string')) !== count($item)) {
                throw new \InvalidArgumentException('Attachment must be an object.');
            }

            /** @var array<string, mixed> $item */
            return ChatAttachment::fromArray($item);
        }, $attachments);
    }

    public function retry(string $workspaceId, string $chatId, string $attachmentId, string $idempotencyKey): ChatAttachment
    {
        return $this->resource('POST', $workspaceId, $chatId, $attachmentId, '/retry', $idempotencyKey);
    }

    public function delete(string $workspaceId, string $chatId, string $attachmentId): ChatAttachment
    {
        return $this->resource('DELETE', $workspaceId, $chatId, $attachmentId);
    }

    public function download(string $workspaceId, string $chatId, string $attachmentId, string $kind, ?ResponseBodySink $sink = null): DownloadResult
    {
        if (! in_array($kind, ['original', 'derived'], true)) {
            throw new \InvalidArgumentException('Download kind must be original or derived.');
        }

        return $this->requests->stream('GET', $this->path($workspaceId, $chatId, '/'.rawurlencode($attachmentId).'/download'), ['kind' => $kind], null, $sink);
    }

    public function wait(string $workspaceId, string $chatId, string $attachmentId, float $timeoutSeconds = 600, int $intervalMilliseconds = 1000, ?CancellationToken $token = null): ChatAttachment
    {
        if (! is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $intervalMilliseconds < 1) {
            throw new \InvalidArgumentException('Polling bounds must be positive.');
        }
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $token?->throwIfCancelled();
            $remaining = max(1, (int) ceil($deadline - microtime(true)));
            $requests = new TypedRequester($this->executor, $this->uris, min($this->connectTimeoutSeconds, $remaining), min($this->requestTimeoutSeconds, $remaining));
            $value = $this->resource('GET', $workspaceId, $chatId, $attachmentId, requests: $requests);
            if (
                in_array($value->lifecycle, ['deleting', 'deleted'], true)
                || $value->conversion->status === 'completed'
                || ($value->conversion->status === 'failed' && ! $value->conversion->recoverable)
                || ($value->conversion->status === 'not_required' && $value->lifecycle === 'ready')
            ) {
                return $value;
            }
            $until = min($deadline, microtime(true) + $intervalMilliseconds / 1000);
            while (microtime(true) < $until) {
                $token?->throwIfCancelled();
                usleep((int) min(100_000, max(1, ($until - microtime(true)) * 1_000_000)));
            }
        } while (microtime(true) < $deadline);
        throw new \RuntimeException('Attachment polling timed out.');
    }

    private function resource(string $method, string $workspaceId, string $chatId, string $attachmentId, string $suffix = '', ?string $key = null, ?TypedRequester $requests = null): ChatAttachment
    {
        $result = ($requests ?? $this->requests)->request($method, $this->path($workspaceId, $chatId, '/'.rawurlencode($attachmentId).$suffix), [], null, ChatAttachment::class, EnvelopeMode::Envelope, retry: $method === 'GET' ? RetrySafety::Safe : RetrySafety::NeverRetry, idempotencyKey: $key);
        assert($result instanceof ChatAttachment);

        return $result;
    }

    private function path(string $workspaceId, string $chatId, string $suffix = ''): string
    {
        return '/workspaces/'.rawurlencode($workspaceId).'/chats/'.rawurlencode($chatId).'/attachments'.$suffix;
    }
}
