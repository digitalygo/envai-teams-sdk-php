<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Agent\Agent;
use Digitalygo\Envai\Models\Agent\AgentCreateRequest;
use Digitalygo\Envai\Models\Agent\AgentUpdateRequest;
use Digitalygo\Envai\Models\Common\ResourceCollection;

/**
 * Control plane operations over agent definitions.
 *
 * Mutations are ambiguous and are never retried automatically; reads are
 * retry-safe.
 */
final readonly class AgentsService
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
     * Creates an agent definition (POST /agents).
     */
    public function create(AgentCreateRequest $request): Agent
    {
        $result = $this->requests->request(
            'POST',
            '/agents',
            [],
            $request->toArray(),
            Agent::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var Agent $result */
        return $result;
    }

    /**
     * Lists the agent definitions owned by the authenticated user
     * (GET /agents).
     *
     * @return ResourceCollection<Agent>
     */
    public function list(): ResourceCollection
    {
        $result = $this->requests->request(
            'GET',
            '/agents',
            [],
            null,
            Agent::class,
            EnvelopeMode::Collection,
        );

        /** @var ResourceCollection<Agent> $result */
        return $result;
    }

    /**
     * Gets an agent definition by id (GET /agents/{agent_id}).
     */
    public function get(string $agentId): Agent
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve('/agents/{agent_id}', ['agent_id' => $agentId]),
            [],
            null,
            Agent::class,
            EnvelopeMode::Envelope,
        );

        /** @var Agent $result */
        return $result;
    }

    /**
     * Updates an agent definition (PUT /agents/{agent_id}).
     */
    public function update(string $agentId, AgentUpdateRequest $request): Agent
    {
        $result = $this->requests->request(
            'PUT',
            $this->requests->resolve('/agents/{agent_id}', ['agent_id' => $agentId]),
            [],
            $request->toArray(),
            Agent::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var Agent $result */
        return $result;
    }

    /**
     * Deletes an agent definition (DELETE /agents/{agent_id}, HTTP 204).
     */
    public function delete(string $agentId): void
    {
        $this->requests->noContent(
            'DELETE',
            $this->requests->resolve('/agents/{agent_id}', ['agent_id' => $agentId]),
            [],
            null,
        );
    }
}
