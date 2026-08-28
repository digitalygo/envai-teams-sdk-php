<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\ApiKey\ApiKey;
use Digitalygo\Envai\Models\ApiKey\ApiKeyCreated;
use Digitalygo\Envai\Models\ApiKey\ApiKeyCreateRequest;
use Digitalygo\Envai\Models\Common\ResourceCollection;

/**
 * Control plane operations over long lived api keys.
 *
 * The plaintext token appears only in the creation response and is never
 * persisted server side; mutations are never retried automatically.
 */
final readonly class ApiKeysService
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
     * Creates an api key; the plaintext token is shown exactly once in the
     * response (POST /api-keys).
     */
    public function create(ApiKeyCreateRequest $request): ApiKeyCreated
    {
        $result = $this->requests->request(
            'POST',
            '/api-keys',
            [],
            $request->toArray(),
            ApiKeyCreated::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var ApiKeyCreated $result */
        return $result;
    }

    /**
     * Lists the api keys owned by the authenticated user with their
     * metadata (GET /api-keys).
     *
     * @return ResourceCollection<ApiKey>
     */
    public function list(): ResourceCollection
    {
        $result = $this->requests->request(
            'GET',
            '/api-keys',
            [],
            null,
            ApiKey::class,
            EnvelopeMode::Collection,
        );

        /** @var ResourceCollection<ApiKey> $result */
        return $result;
    }

    /**
     * Revokes an api key (DELETE /api-keys/{key_id}, HTTP 204).
     */
    public function delete(string $keyId): void
    {
        $this->requests->noContent(
            'DELETE',
            $this->requests->resolve('/api-keys/{key_id}', ['key_id' => $keyId]),
            [],
            null,
        );
    }
}
