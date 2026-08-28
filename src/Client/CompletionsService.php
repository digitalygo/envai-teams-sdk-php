<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Models\Completion\Completion;
use Digitalygo\Envai\Models\Completion\CompletionFilters;
use Digitalygo\Envai\Pagination\Page;

/**
 * Metadata-only completion records of a chat.
 *
 * Records never include message content; content lives in the execution
 * plane stream. The listing is paginated with the Laravel paginator wire
 * shape `{data, links, meta}` and is decoded into a Page.
 */
final readonly class CompletionsService
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
     * Lists the completion metadata of a chat, newest first, with optional
     * filters (GET .../completions).
     *
     * @return Page<Completion>
     */
    public function list(string $workspaceId, string $chatId, ?CompletionFilters $filters = null): Page
    {
        $query = $filters?->toArray() ?? [];

        $payload = $this->requests->payload(
            'GET',
            $this->requests->resolve(
                '/workspaces/{workspace_id}/chats/{chat_id}/completions',
                ['workspace_id' => $workspaceId, 'chat_id' => $chatId],
            ),
            $query,
            null,
        );

        /** @var Page<Completion> */
        return Page::fromArray($payload, Completion::class);
    }

    /**
     * Gets the metadata-only record of a single completion (GET
     * .../completions/{completion_id}).
     */
    public function get(string $workspaceId, string $chatId, string $completionId): Completion
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve(
                '/workspaces/{workspace_id}/chats/{chat_id}/completions/{completion_id}',
                ['workspace_id' => $workspaceId, 'chat_id' => $chatId, 'completion_id' => $completionId],
            ),
            [],
            null,
            Completion::class,
            EnvelopeMode::Envelope,
        );

        /** @var Completion $result */
        return $result;
    }
}
