<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

/**
 * High level facade over chat operations in one workspace. The workspace scope
 * never touches definitions readiness, which is connection-scoped.
 */
final readonly class WorkspaceScope
{
    public function __construct(
        private readonly ChatsService $chats,
        private readonly string $workspaceId,
    ) {}

    /**
     * The chat control plane of this workspace.
     */
    public function chats(): WorkspaceChats
    {
        return new WorkspaceChats($this->chats, $this->workspaceId);
    }
}
