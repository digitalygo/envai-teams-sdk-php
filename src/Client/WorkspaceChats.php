<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Models\Chat\Chat;
use Digitalygo\Envai\Models\Chat\ChatCreateRequest;
use Digitalygo\Envai\Models\Chat\ChatUpdateRequest;
use Digitalygo\Envai\Models\Common\CleanupResult;
use Digitalygo\Envai\Models\Common\ResourceCollection;

/**
 * Chat control plane facade scoped to one workspace.
 *
 * The workspace continues to be the scope of chat operations while
 * readiness (definitions) is scoped to connection/account. create() accepts either a ChatCreateRequest or a
 * plain team id, so the documented readiness example works verbatim:
 *
 *     $chat = $envai->workspace($id)->chats()->create(team: $definitions->team('forecasting'));
 */
final readonly class WorkspaceChats
{
    public function __construct(
        private readonly ChatsService $chats,
        private readonly string $workspaceId,
    ) {}

    /**
     * Creates a chat inside the workspace selecting the team (POST
     * /workspaces/{workspace_id}/chats). The team id is mandatory and is
     * validated by the server to exist and belong to the caller.
     */
    public function create(ChatCreateRequest|string $team): Chat
    {
        $request = $team instanceof ChatCreateRequest ? $team : new ChatCreateRequest($team);

        return $this->chats->createChat($this->workspaceId, $request);
    }

    /**
     * Lists the chats of the workspace (GET /workspaces/{workspace_id}/chats).
     *
     * @return ResourceCollection<Chat>
     */
    public function list(): ResourceCollection
    {
        return $this->chats->listChats($this->workspaceId);
    }

    public function update(string $chatId, ChatUpdateRequest $request): Chat
    {
        return $this->chats->updateChat($this->workspaceId, $chatId, $request);
    }

    public function delete(string $chatId): CleanupResult
    {
        return $this->chats->deleteChat($this->workspaceId, $chatId);
    }

    /**
     * Gets one chat of the workspace (GET /workspaces/{workspace_id}/chats/{chat_id}).
     */
    public function get(string $chatId): Chat
    {
        return $this->chats->getChat($this->workspaceId, $chatId);
    }
}
