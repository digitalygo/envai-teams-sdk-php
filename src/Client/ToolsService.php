<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Common\ResourceCollection;
use Digitalygo\Envai\Models\Tool\UserTool;
use Digitalygo\Envai\Models\Tool\UserToolCreateRequest;
use Digitalygo\Envai\Models\Tool\UserToolUpdateRequest;

/**
 * Control plane operations over the caller's client tool registry.
 *
 * Tools are user-scoped and shared across all the caller's workspaces.
 * Mutations are ambiguous and are never retried automatically; reads are
 * retry-safe.
 */
final readonly class ToolsService
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
     * Registers a client tool (POST /tools).
     */
    public function create(UserToolCreateRequest $request): UserTool
    {
        $result = $this->requests->request(
            'POST',
            '/tools',
            [],
            $request->toArray(),
            UserTool::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var UserTool $result */
        return $result;
    }

    /**
     * Lists the client tools registered by the caller (GET /tools).
     *
     * @return ResourceCollection<UserTool>
     */
    public function list(): ResourceCollection
    {
        $result = $this->requests->request(
            'GET',
            '/tools',
            [],
            null,
            UserTool::class,
            EnvelopeMode::Collection,
        );

        /** @var ResourceCollection<UserTool> $result */
        return $result;
    }

    /**
     * Gets a client tool by name (GET /tools/{name}).
     */
    public function get(string $name): UserTool
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve('/tools/{name}', ['name' => $name]),
            [],
            null,
            UserTool::class,
            EnvelopeMode::Envelope,
        );

        /** @var UserTool $result */
        return $result;
    }

    /**
     * Updates a client tool (PUT /tools/{name}).
     */
    public function update(string $name, UserToolUpdateRequest $request): UserTool
    {
        $result = $this->requests->request(
            'PUT',
            $this->requests->resolve('/tools/{name}', ['name' => $name]),
            [],
            $request->toArray(),
            UserTool::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var UserTool $result */
        return $result;
    }

    /**
     * Deletes a client tool (DELETE /tools/{name}, HTTP 204).
     */
    public function delete(string $name): void
    {
        $this->requests->noContent(
            'DELETE',
            $this->requests->resolve('/tools/{name}', ['name' => $name]),
            [],
            null,
        );
    }
}
