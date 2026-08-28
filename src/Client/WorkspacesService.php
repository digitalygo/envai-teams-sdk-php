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
use Digitalygo\Envai\Models\Workspace\Workspace;
use Digitalygo\Envai\Models\Workspace\WorkspaceCreateRequest;
use Digitalygo\Envai\Models\Workspace\WorkspaceUpdateRequest;

/**
 * Control plane operations over workspaces.
 *
 * A workspace owns the wiki, skills and MEMORY.md state and does not own a
 * team; teams are selected per chat at chat creation. Create and update are
 * ambiguous mutations and are never retried automatically.
 */
final readonly class WorkspacesService
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
     * Creates a workspace (POST /workspaces).
     */
    public function create(WorkspaceCreateRequest $request): Workspace
    {
        $result = $this->requests->request(
            'POST',
            '/workspaces',
            [],
            $request->toArray(),
            Workspace::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var Workspace $result */
        return $result;
    }

    /**
     * Lists the workspaces owned by the authenticated user
     * (GET /workspaces).
     *
     * @return ResourceCollection<Workspace>
     */
    public function list(): ResourceCollection
    {
        $result = $this->requests->request(
            'GET',
            '/workspaces',
            [],
            null,
            Workspace::class,
            EnvelopeMode::Collection,
        );

        /** @var ResourceCollection<Workspace> $result */
        return $result;
    }

    /**
     * Gets a workspace by id (GET /workspaces/{workspace_id}).
     */
    public function get(string $workspaceId): Workspace
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve('/workspaces/{workspace_id}', ['workspace_id' => $workspaceId]),
            [],
            null,
            Workspace::class,
            EnvelopeMode::Envelope,
        );

        /** @var Workspace $result */
        return $result;
    }

    /**
     * Updates a workspace (PUT /workspaces/{workspace_id}).
     */
    public function update(string $workspaceId, WorkspaceUpdateRequest $request): Workspace
    {
        $result = $this->requests->request(
            'PUT',
            $this->requests->resolve('/workspaces/{workspace_id}', ['workspace_id' => $workspaceId]),
            [],
            $request->toArray(),
            Workspace::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var Workspace $result */
        return $result;
    }
}
