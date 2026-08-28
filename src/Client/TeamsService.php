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
use Digitalygo\Envai\Models\Team\Team;
use Digitalygo\Envai\Models\Team\TeamCreateRequest;
use Digitalygo\Envai\Models\Team\TeamUpdateRequest;

/**
 * Control plane operations over teams.
 *
 * A team has exactly one orchestrator and an ordered member list; mutations
 * are ambiguous and are never retried automatically, reads are retry-safe.
 */
final readonly class TeamsService
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
     * Creates a team (POST /teams).
     */
    public function create(TeamCreateRequest $request): Team
    {
        $result = $this->requests->request(
            'POST',
            '/teams',
            [],
            $request->toArray(),
            Team::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var Team $result */
        return $result;
    }

    /**
     * Lists the teams owned by the authenticated user (GET /teams).
     *
     * @return ResourceCollection<Team>
     */
    public function list(): ResourceCollection
    {
        $result = $this->requests->request(
            'GET',
            '/teams',
            [],
            null,
            Team::class,
            EnvelopeMode::Collection,
        );

        /** @var ResourceCollection<Team> $result */
        return $result;
    }

    /**
     * Gets a team by id (GET /teams/{team_id}).
     */
    public function get(string $teamId): Team
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve('/teams/{team_id}', ['team_id' => $teamId]),
            [],
            null,
            Team::class,
            EnvelopeMode::Envelope,
        );

        /** @var Team $result */
        return $result;
    }

    /**
     * Updates a team (PUT /teams/{team_id}).
     */
    public function update(string $teamId, TeamUpdateRequest $request): Team
    {
        $result = $this->requests->request(
            'PUT',
            $this->requests->resolve('/teams/{team_id}', ['team_id' => $teamId]),
            [],
            $request->toArray(),
            Team::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var Team $result */
        return $result;
    }

    /**
     * Deletes a team (DELETE /teams/{team_id}, HTTP 204).
     */
    public function delete(string $teamId): void
    {
        $this->requests->noContent(
            'DELETE',
            $this->requests->resolve('/teams/{team_id}', ['team_id' => $teamId]),
            [],
            null,
        );
    }
}
