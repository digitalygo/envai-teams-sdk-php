<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

/**
 * Machine readable inventory of every operation in the Envai OpenAPI
 * contract, keyed by operationId.
 *
 * This table is the single source of truth used by the quality gate to
 * verify that the SDK covers the whole API surface: the inventory test
 * parses docs/openapi.yaml and asserts that this constant matches the
 * parsed set exactly, with no missing and no extra entries. Paths keep the
 * OpenAPI `{placeholder}` syntax; services resolve them with concrete ids
 * before calling the executor.
 */
final class OperationInventory
{
    /**
     * operationId => ['method' => uppercase HTTP method, 'path' => OpenAPI path].
     *
     * @var array<string, array{method: string, path: string}>
     */
    public const OPERATIONS = [
        'sendChatMessage' => ['method' => 'POST', 'path' => '/atp/v3/chats/{chat_id}/messages'],
        'streamChatEvents' => ['method' => 'GET', 'path' => '/atp/v3/chats/{chat_id}/events'],
        'cancelChatTurn' => ['method' => 'POST', 'path' => '/atp/v3/chats/{chat_id}/cancel'],
        'steerChatTurn' => ['method' => 'POST', 'path' => '/atp/v3/chats/{chat_id}/steer'],
        'resolveChatTool' => ['method' => 'POST', 'path' => '/atp/v3/chats/{chat_id}/tool-output'],
        'resolveChatApproval' => ['method' => 'POST', 'path' => '/atp/v3/chats/{chat_id}/approval'],
        'forkChat' => ['method' => 'POST', 'path' => '/atp/v3/chats/{chat_id}/fork'],
        'restoreChat' => ['method' => 'POST', 'path' => '/atp/v3/chats/{chat_id}/restore'],
        'getCapabilities' => ['method' => 'GET', 'path' => '/atp/v3/capabilities'],
        'healthCheck' => ['method' => 'GET', 'path' => '/up'],
        'getJwks' => ['method' => 'GET', 'path' => '/.well-known/jwks.json'],
        'createWorkspace' => ['method' => 'POST', 'path' => '/workspaces'],
        'listWorkspaces' => ['method' => 'GET', 'path' => '/workspaces'],
        'getWorkspace' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}'],
        'updateWorkspace' => ['method' => 'PUT', 'path' => '/workspaces/{workspace_id}'],
        'createUserTool' => ['method' => 'POST', 'path' => '/tools'],
        'listUserTools' => ['method' => 'GET', 'path' => '/tools'],
        'getUserTool' => ['method' => 'GET', 'path' => '/tools/{name}'],
        'updateUserTool' => ['method' => 'PUT', 'path' => '/tools/{name}'],
        'deleteUserTool' => ['method' => 'DELETE', 'path' => '/tools/{name}'],
        'createChat' => ['method' => 'POST', 'path' => '/workspaces/{workspace_id}/chats'],
        'listChats' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/chats'],
        'getChat' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/chats/{chat_id}'],
        'listCompletions' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/chats/{chat_id}/completions'],
        'getCompletion' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/chats/{chat_id}/completions/{completion_id}'],
        'indexWorkspaceFiles' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/files'],
        'createWorkspaceFile' => ['method' => 'POST', 'path' => '/workspaces/{workspace_id}/files'],
        'updateWorkspaceFile' => ['method' => 'PUT', 'path' => '/workspaces/{workspace_id}/files'],
        'deleteWorkspaceFile' => ['method' => 'DELETE', 'path' => '/workspaces/{workspace_id}/files'],
        'downloadWorkspaceFile' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/files/download'],
        'convertWorkspaceFileToPdf' => ['method' => 'POST', 'path' => '/workspaces/{workspace_id}/files/convert-to-pdf'],
        'createAgent' => ['method' => 'POST', 'path' => '/agents'],
        'listAgents' => ['method' => 'GET', 'path' => '/agents'],
        'getAgent' => ['method' => 'GET', 'path' => '/agents/{agent_id}'],
        'updateAgent' => ['method' => 'PUT', 'path' => '/agents/{agent_id}'],
        'deleteAgent' => ['method' => 'DELETE', 'path' => '/agents/{agent_id}'],
        'createTeam' => ['method' => 'POST', 'path' => '/teams'],
        'listTeams' => ['method' => 'GET', 'path' => '/teams'],
        'getTeam' => ['method' => 'GET', 'path' => '/teams/{team_id}'],
        'updateTeam' => ['method' => 'PUT', 'path' => '/teams/{team_id}'],
        'deleteTeam' => ['method' => 'DELETE', 'path' => '/teams/{team_id}'],
        'listModels' => ['method' => 'GET', 'path' => '/models'],
        'createApiKey' => ['method' => 'POST', 'path' => '/api-keys'],
        'listApiKeys' => ['method' => 'GET', 'path' => '/api-keys'],
        'deleteApiKey' => ['method' => 'DELETE', 'path' => '/api-keys/{key_id}'],
        'testGenerateImage' => ['method' => 'POST', 'path' => '/tests/images/generate'],
        'createImageGeneration' => ['method' => 'POST', 'path' => '/images/generations'],
        'getImageGeneration' => ['method' => 'GET', 'path' => '/images/generations/{record}'],
        'uploadImage' => ['method' => 'POST', 'path' => '/images/uploads'],
        'listImageModels' => ['method' => 'GET', 'path' => '/images/models'],
        'downloadArtifact' => ['method' => 'GET', 'path' => '/artifacts/download'],
        'handleProviderWebhook' => ['method' => 'POST', 'path' => '/webhooks/providers/{provider}'],
        'uploadWikiSource' => ['method' => 'POST', 'path' => '/workspaces/{workspace_id}/files/sources'],
        'getWikiSource' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/files/sources/{file_id}'],
        'downloadWikiSource' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/files/sources/{file_id}/download'],
        'withdrawWikiSource' => ['method' => 'POST', 'path' => '/workspaces/{workspace_id}/wiki/outbox/files'],
        'createWikiInboxRun' => ['method' => 'POST', 'path' => '/workspaces/{workspace_id}/wiki/inbox/runs'],
        'createWikiOutboxRun' => ['method' => 'POST', 'path' => '/workspaces/{workspace_id}/wiki/outbox/runs'],
        'createWikiSyncRun' => ['method' => 'POST', 'path' => '/workspaces/{workspace_id}/wiki/syncs'],
        'getWikiOperation' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/wiki/operations/{operation_id}'],
        'listWikiCategories' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/wiki/categories'],
        'getWikiCategoryIndex' => ['method' => 'GET', 'path' => '/workspaces/{workspace_id}/wiki/categories/{category_id}/index'],
        'getWikiWebhookJwks' => ['method' => 'GET', 'path' => '/.well-known/wiki-webhook-jwks.json'],
        'streamChatEventsLegacy' => ['method' => 'GET', 'path' => '/chats/{chat_id}/events'],
        'getCapabilitiesLegacy' => ['method' => 'GET', 'path' => '/capabilities'],
    ];
}
