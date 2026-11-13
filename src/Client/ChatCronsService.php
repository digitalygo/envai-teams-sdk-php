<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\ChatCron\ChatCron;
use Digitalygo\Envai\Models\ChatCron\ChatCronFilters;
use Digitalygo\Envai\Models\ChatCron\ChatCronUpdateRequest;
use Digitalygo\Envai\Models\Common\ResourceCollection;

final readonly class ChatCronsService
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

    /** @return ResourceCollection<ChatCron> */
    public function list(string $workspaceId, ?ChatCronFilters $filters = null): ResourceCollection
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve('/workspaces/{workspace_id}/crons', ['workspace_id' => $workspaceId]),
            $filters?->toArray() ?? [],
            null,
            ChatCron::class,
            EnvelopeMode::Collection,
        );

        /** @var ResourceCollection<ChatCron> $result */
        return $result;
    }

    public function get(string $workspaceId, string $cronId): ChatCron
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve(
                '/workspaces/{workspace_id}/crons/{cron_id}',
                ['workspace_id' => $workspaceId, 'cron_id' => $cronId],
            ),
            [],
            null,
            ChatCron::class,
            EnvelopeMode::Envelope,
        );

        /** @var ChatCron $result */
        return $result;
    }

    public function update(string $workspaceId, string $cronId, ChatCronUpdateRequest $request): ChatCron
    {
        $result = $this->requests->request(
            'PATCH',
            $this->requests->resolve(
                '/workspaces/{workspace_id}/crons/{cron_id}',
                ['workspace_id' => $workspaceId, 'cron_id' => $cronId],
            ),
            [],
            $request->toArray(),
            ChatCron::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var ChatCron $result */
        return $result;
    }

    public function delete(string $workspaceId, string $cronId): void
    {
        $this->requests->noContent(
            'DELETE',
            $this->requests->resolve(
                '/workspaces/{workspace_id}/crons/{cron_id}',
                ['workspace_id' => $workspaceId, 'cron_id' => $cronId],
            ),
            [],
            null,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );
    }
}
