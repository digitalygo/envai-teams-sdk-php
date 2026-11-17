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
use Digitalygo\Envai\Models\ProjectSkill\ProjectSkill;
use Digitalygo\Envai\Models\ProjectSkill\ProjectSkillCreateRequest;
use Digitalygo\Envai\Models\ProjectSkill\ProjectSkillUpdateRequest;

final readonly class ProjectSkillsService
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

    /** @return ResourceCollection<ProjectSkill> */
    public function list(string $projectId): ResourceCollection
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve('/projects/{project_id}/skills', ['project_id' => $projectId]),
            [],
            null,
            ProjectSkill::class,
            EnvelopeMode::Collection,
        );

        /** @var ResourceCollection<ProjectSkill> $result */
        return $result;
    }

    public function create(string $projectId, ProjectSkillCreateRequest $request): ProjectSkill
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/projects/{project_id}/skills', ['project_id' => $projectId]),
            [],
            $request->toArray(),
            ProjectSkill::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var ProjectSkill $result */
        return $result;
    }

    public function get(string $projectId, string $skillId): ProjectSkill
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve(
                '/projects/{project_id}/skills/{skill_id}',
                ['project_id' => $projectId, 'skill_id' => $skillId],
            ),
            [],
            null,
            ProjectSkill::class,
            EnvelopeMode::Envelope,
        );

        /** @var ProjectSkill $result */
        return $result;
    }

    public function update(string $projectId, string $skillId, ProjectSkillUpdateRequest $request): ProjectSkill
    {
        $result = $this->requests->request(
            'PUT',
            $this->requests->resolve(
                '/projects/{project_id}/skills/{skill_id}',
                ['project_id' => $projectId, 'skill_id' => $skillId],
            ),
            [],
            $request->toArray(),
            ProjectSkill::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var ProjectSkill $result */
        return $result;
    }

    public function delete(string $projectId, string $skillId): void
    {
        $this->requests->noContent(
            'DELETE',
            $this->requests->resolve(
                '/projects/{project_id}/skills/{skill_id}',
                ['project_id' => $projectId, 'skill_id' => $skillId],
            ),
            [],
            null,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );
    }
}
