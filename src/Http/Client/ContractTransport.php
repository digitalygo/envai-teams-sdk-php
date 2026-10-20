<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Client;

use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Contracts\EventStreamTransport;
use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Exceptions\ContractCompatibilityException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Exceptions\UnsupportedCapabilityException;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartField;
use Digitalygo\Envai\Http\StreamRequest;

final class ContractTransport implements EventStreamTransport, HttpTransport
{
    private bool $verifiedCapabilities = false;

    /** @var array<mixed> */
    private array $features = [];

    public function __construct(
        private readonly HttpTransport&EventStreamTransport $transport,
        private readonly UriResolver $uris,
        private readonly int $version = 3,
        private readonly int $maxLegacyReplayBytes = 67_108_864,
    ) {
        if ($maxLegacyReplayBytes < 1) {
            throw new \InvalidArgumentException('maxLegacyReplayBytes must be positive.');
        }
        if (! in_array($version, [2, 3], true)) {
            throw new \InvalidArgumentException('Supported contract versions are 2 and 3.');
        }
    }

    public function send(HttpRequest $request): HttpResponse
    {
        if ($this->version === 2 && strtoupper($request->method) !== 'GET') {
            throw new ContractCompatibilityException('Contract 2 read-only mode permits GET requests only.');
        }
        $kind = $this->kind($request->uri);
        if ($kind === null) {
            return $this->transport->send($request);
        }
        $mutation = ! in_array(strtoupper($request->method), ['GET', 'HEAD'], true);
        if ($this->version === 2 && ($mutation || in_array($kind, ['wiki', 'attachment'], true))) {
            throw new ContractCompatibilityException('Contract 2 supports historical reads only; select contract 3 for this operation.');
        }
        $headers = $this->selectedHeaders($request->headers);
        $uri = $this->selectedUri($request->uri, $kind);
        $attachmentIds = [];
        if ($mutation && is_string($request->body) && preg_match('#/chats/[^/]+/messages$#', $request->uri)) {
            $body = json_decode($request->body, true);
            $attachmentIds = is_array($body) ? ($body['attachment_ids'] ?? []) : [];
            if ($attachmentIds !== [] && ! isset($request->headers['idempotency-key'])) {
                throw new \InvalidArgumentException('Attachment messages require Idempotency-Key.');
            }
        }
        if ($mutation) {
            $this->preflight($request);
            $chatAttachmentUpload = $this->isChatAttachmentUpload($request);
            if ($chatAttachmentUpload) {
                if (! $this->hasFeature('chat_attachments')) {
                    throw new UnsupportedCapabilityException('Server does not advertise chat_attachments.');
                }
                if (! $this->hasBooleanFeature('chat_attachment_originals')) {
                    throw new UnsupportedCapabilityException('Server does not advertise chat_attachment_originals.');
                }
            } elseif (($kind === 'attachment' || $attachmentIds !== []) && ! $this->hasFeature('chat_attachments')) {
                throw new ContractCompatibilityException('Server does not advertise chat_attachments.');
            }
            if ($kind === 'wiki' && $this->usesOriginalSourceProcessing($request) && ! $this->hasBooleanFeature('wiki_original_sources')) {
                throw new UnsupportedCapabilityException('Server does not advertise wiki_original_sources.');
            }
        }
        $response = $this->transport->send(new HttpRequest(
            $request->method, $uri, $headers,
            $request->body, $request->sink, $request->connectTimeoutSeconds,
            $request->requestTimeoutSeconds, $request->auth, $request->retrySafety,
        ));
        $this->validateResponse($response);
        if ($this->version === 3 && $response->status === 404 && in_array($kind, ['atp', 'capabilities'], true)) {
            throw new ContractCompatibilityException('The server rejected the contract 3 route; no fallback was attempted.');
        }
        if ($response->status >= 200 && $response->status < 300) {
            if ($attachmentIds !== [] && ($this->json($response)['attachment_ids'] ?? null) !== $attachmentIds) {
                throw new ContractCompatibilityException('Message acceptance omitted or changed attachment IDs.');
            }
            if ($kind === 'capabilities') {
                $this->validateCapabilities($response);
            } elseif ($kind === 'completion' && $this->version === 2) {
                $this->validateLegacyCompletion($response);
            }
        }

        return $response;
    }

    public function open(StreamRequest $request): EventStreamConnection
    {
        $kind = $this->kind($request->uri);
        if ($kind !== 'atp') {
            return $this->transport->open($request);
        }
        $headers = $this->selectedHeaders($request->headers);
        try {
            $connection = $this->transport->open(new StreamRequest(
                $this->selectedUri($request->uri, $kind), $headers,
                $request->connectTimeoutSeconds, $request->responseHeaderTimeoutSeconds,
                $request->streamTimeoutSeconds, $request->cancellationToken,
            ));
        } catch (TransportException $exception) {
            if (in_array($exception->context()['http_status'] ?? null, [400, 404, 426], true)) {
                throw new ContractCompatibilityException('The server rejected the negotiated stream contract.', previous: $exception);
            }
            throw $exception;
        }
        try {
            $status = $connection->statusCode();
            if ($status < 200 || $status >= 300) {
                throw new ContractCompatibilityException('The negotiated event stream was rejected with HTTP '.$status.'.');
            }
            $this->assertAcknowledgment($connection->headers());
        } catch (\Throwable $exception) {
            $connection->close();
            throw $exception;
        }

        if ($this->version === 2) {
            try {
                $path = (string) parse_url($request->uri, PHP_URL_PATH);
                parse_str((string) parse_url($request->uri, PHP_URL_QUERY), $query);
                $after = $query['after'] ?? '0';
                if (! preg_match('#/chats/([^/]+)/events$#', $path, $matches) || ! is_string($after) || ! preg_match('/^[0-9]+$/D', $after) || filter_var($after, FILTER_VALIDATE_INT) === false) {
                    throw new ContractCompatibilityException('Legacy replay requires a chat events route and a nonnegative integer cursor.');
                }

                return LegacyReplayConnection::capture($connection, $request->cancellationToken, $this->maxLegacyReplayBytes, rawurldecode($matches[1]), (int) $after);
            } catch (\Throwable $exception) {
                $connection->close();
                throw $exception;
            }
        }

        return $connection;
    }

    private function preflight(HttpRequest $request): void
    {
        if ($this->verifiedCapabilities) {
            return;
        }
        $headers = ['x-envai-contract-version' => ['3'], 'accept' => ['application/json']];
        if (isset($request->headers['authorization'])) {
            $headers['authorization'] = $request->headers['authorization'];
        }
        $response = $this->transport->send(new HttpRequest(
            'GET', $this->uris->api('/atp/v3/capabilities'), $headers,
            connectTimeoutSeconds: $request->connectTimeoutSeconds,
            requestTimeoutSeconds: $request->requestTimeoutSeconds,
        ));
        $this->validateResponse($response);
        if ($response->status !== 200) {
            throw new ContractCompatibilityException('Contract 3 capabilities preflight failed with HTTP '.$response->status.'.');
        }
        $this->validateCapabilities($response);
    }

    private function validateCapabilities(HttpResponse $response): void
    {
        $data = $this->json($response);
        if (($data['protocol_version'] ?? null) !== $this->version) {
            throw new ContractCompatibilityException('Capabilities protocol version does not match the selected contract.');
        }
        $supported = $data['supported_contract_versions'] ?? null;
        if ($this->version === 3 && (($data['contract_version'] ?? null) !== 3 || ! is_array($supported) || ! array_is_list($supported) || count(array_filter($supported, 'is_int')) !== count($supported) || ! in_array(3, $supported, true))) {
            throw new ContractCompatibilityException('Server does not advertise contract 3 support.');
        }
        $this->features = is_array($data['features'] ?? null) ? $data['features'] : [];
        $this->verifiedCapabilities = true;
    }

    private function hasFeature(string $name): bool
    {
        if (array_is_list($this->features)) {
            return in_array($name, $this->features, true);
        }

        return ($this->features[$name] ?? null) === true;
    }

    private function hasBooleanFeature(string $name): bool
    {
        return ! array_is_list($this->features) && ($this->features[$name] ?? null) === true;
    }

    private function isChatAttachmentUpload(HttpRequest $request): bool
    {
        return strtoupper($request->method) === 'POST'
            && preg_match('#/workspaces/[^/]+/chats/[^/]+/attachments$#', (string) parse_url($request->uri, PHP_URL_PATH)) === 1;
    }

    private function usesOriginalSourceProcessing(HttpRequest $request): bool
    {
        if (strtoupper($request->method) !== 'POST' || ! $request->body instanceof MultipartBody) {
            return false;
        }
        if (preg_match('#/workspaces/[^/]+/files/sources$#', (string) parse_url($request->uri, PHP_URL_PATH)) !== 1) {
            return false;
        }

        foreach ($request->body->parts as $part) {
            if ($part instanceof MultipartField && $part->name === 'processing' && $part->value === 'original') {
                return true;
            }
        }

        return false;
    }

    private function validateResponse(HttpResponse $response): void
    {
        if ($response->status >= 200 && $response->status < 300) {
            $this->assertAcknowledgment($response->headers);
        } elseif (in_array($response->status, [400, 404, 426], true)) {
            $error = $this->json($response, false)['error'] ?? [];
            $code = is_array($error) ? ($error['code'] ?? null) : null;
            if ($response->status === 426 || in_array($code, ['unsupported_contract_version', 'contract_upgrade_required'], true)) {
                throw new ContractCompatibilityException('The server cannot serve the selected contract (HTTP '.$response->status.').');
            }
        }
    }

    /** @param array<string, list<string>> $headers */
    private function assertAcknowledgment(array $headers): void
    {
        if (($headers['x-envai-contract-version'] ?? []) !== [(string) $this->version]) {
            throw new ContractCompatibilityException('Missing or mismatched X-Envai-Contract-Version acknowledgment.');
        }
    }

    /** @param array<string, list<string>> $headers
     * @return array<string, list<string>>
     */
    private function selectedHeaders(array $headers): array
    {
        if (isset($headers['x-envai-contract-version']) && $headers['x-envai-contract-version'] !== [(string) $this->version]) {
            throw new ContractCompatibilityException('Conflicting contract selector.');
        }
        $headers['x-envai-contract-version'] = [(string) $this->version];

        return $headers;
    }

    private function selectedUri(string $uri, string $kind): string
    {
        if (! in_array($kind, ['atp', 'capabilities'], true)) {
            return $uri;
        }
        if ($this->version === 3) {
            if (! str_contains((string) parse_url($uri, PHP_URL_PATH), '/api/v1/atp/v3/')) {
                throw new ContractCompatibilityException('Legacy ATP routes require explicit contract 2 read-only mode.');
            }

            return $uri;
        }

        return str_replace('/api/v1/atp/v3/', '/api/v1/', $uri);
    }

    private function kind(string $uri): ?string
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }
        if (preg_match('#/api/v1/(?:atp/v3/)?capabilities$#', $path)) {
            return 'capabilities';
        }
        if (preg_match('#/api/v1/(?:atp/v3/)?chats/[^/]+(?:/|$)#', $path)) {
            return 'atp';
        }
        if (preg_match('#/api/v1/workspaces/[^/]+/chats/[^/]+/attachments(?:/|$)#', $path)) {
            return 'attachment';
        }
        if (preg_match('#/api/v1/workspaces/[^/]+/chats/[^/]+/completions(?:/|$)#', $path)) {
            return 'completion';
        }
        if (preg_match('#/api/v1/workspaces/[^/]+/(?:wiki(?:/|$)|files/sources(?:/|$))#', $path)) {
            return 'wiki';
        }

        return null;
    }

    private function validateLegacyCompletion(HttpResponse $response): void
    {
        $data = $this->json($response)['data'] ?? null;
        if (! is_array($data)) {
            throw new ContractCompatibilityException('Invalid legacy completion response.');
        }
        $rows = array_is_list($data) ? $data : [$data];
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['cost_usd']) || ! $this->validLegacyCost($row['cost_usd'])) {
                throw new ContractCompatibilityException('Contract 2 completion costs must be finite nonnegative numbers.');
            }
            $this->assertLegacyNestedCosts($row['usage'] ?? []);
        }
    }

    private function validLegacyCost(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0;
    }

    private function assertLegacyNestedCosts(mixed $node): void
    {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if (in_array($key, ['cost', 'cost_usd', 'chat_cost'], true) && ! $this->validLegacyCost($value)) {
                throw new ContractCompatibilityException('Nested contract 2 usage costs must be finite nonnegative numbers.');
            }
            $this->assertLegacyNestedCosts($value);
        }
    }

    /** @return array<string, mixed> */
    private function json(HttpResponse $response, bool $required = true): array
    {
        $data = json_decode((string) $response->body, true);
        if (! is_array($data) || array_is_list($data)) {
            if ($required) {
                throw new ContractCompatibilityException('Invalid negotiated JSON response.');
            }

            return [];
        }

        $result = [];
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw new ContractCompatibilityException('Negotiated response must be a JSON object.');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
