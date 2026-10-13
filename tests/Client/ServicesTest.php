<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Exceptions\ConflictException;
use Digitalygo\Envai\Http\DownloadResult;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartField;
use Digitalygo\Envai\Http\MultipartFile;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Agent\Agent;
use Digitalygo\Envai\Models\Agent\AgentCreateRequest;
use Digitalygo\Envai\Models\Agent\AgentMode;
use Digitalygo\Envai\Models\Agent\AgentTool;
use Digitalygo\Envai\Models\Agent\AgentUpdateRequest;
use Digitalygo\Envai\Models\ApiKey\ApiKey;
use Digitalygo\Envai\Models\ApiKey\ApiKeyCreated;
use Digitalygo\Envai\Models\ApiKey\ApiKeyCreateRequest;
use Digitalygo\Envai\Models\ApiKey\ApiKeyExpiry;
use Digitalygo\Envai\Models\ApiKey\ApiKeyScope;
use Digitalygo\Envai\Models\Chat\ApprovalDecisionRequest;
use Digitalygo\Envai\Models\Chat\Chat;
use Digitalygo\Envai\Models\Chat\ChatCreateRequest;
use Digitalygo\Envai\Models\Chat\ChatForked;
use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use Digitalygo\Envai\Models\Chat\ForkChatRequest;
use Digitalygo\Envai\Models\Chat\OrchestratorContextItem;
use Digitalygo\Envai\Models\Chat\OrchestratorContextKind;
use Digitalygo\Envai\Models\Chat\RestoreChatAccepted;
use Digitalygo\Envai\Models\Chat\RestoreChatRequest;
use Digitalygo\Envai\Models\Chat\SteerRequest;
use Digitalygo\Envai\Models\Chat\ToolOutputRequest;
use Digitalygo\Envai\Models\Chat\TurnAccepted;
use Digitalygo\Envai\Models\Common\ResourceCollection;
use Digitalygo\Envai\Models\Completion\Completion;
use Digitalygo\Envai\Models\Completion\CompletionFilters;
use Digitalygo\Envai\Models\Completion\CompletionStatus;
use Digitalygo\Envai\Models\Image\ImageGenerationAcceptedRecord;
use Digitalygo\Envai\Models\Image\ImageGenerationRequest;
use Digitalygo\Envai\Models\Image\ImageGenerationStatusRecord;
use Digitalygo\Envai\Models\Image\ImageUploadRecord;
use Digitalygo\Envai\Models\Image\ModelCatalogEntry;
use Digitalygo\Envai\Models\Image\ProviderWebhookPayload;
use Digitalygo\Envai\Models\Image\TestImageGenerateRequest;
use Digitalygo\Envai\Models\Image\TestImageGenerateResult;
use Digitalygo\Envai\Models\Image\WebhookOk;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Models\Model\ModelList;
use Digitalygo\Envai\Models\System\Capabilities;
use Digitalygo\Envai\Models\System\HealthStatus;
use Digitalygo\Envai\Models\System\JwksDocument;
use Digitalygo\Envai\Models\Team\Team;
use Digitalygo\Envai\Models\Team\TeamCreateRequest;
use Digitalygo\Envai\Models\Team\TeamMemberCreateRequest;
use Digitalygo\Envai\Models\Team\TeamUpdateRequest;
use Digitalygo\Envai\Models\Tool\UserTool;
use Digitalygo\Envai\Models\Tool\UserToolCreateRequest;
use Digitalygo\Envai\Models\Tool\UserToolUpdateRequest;
use Digitalygo\Envai\Models\Workspace\Workspace;
use Digitalygo\Envai\Models\Workspace\WorkspaceCreateRequest;
use Digitalygo\Envai\Models\Workspace\WorkspaceUpdateRequest;
use Digitalygo\Envai\Models\WorkspaceFile\ConvertToPdfRequest;
use Digitalygo\Envai\Models\WorkspaceFile\WorkspaceFile;
use Digitalygo\Envai\Models\WorkspaceFile\WorkspaceFileRequest;
use Digitalygo\Envai\Pagination\Page;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * FakeTransport driven coverage of every one of the 52 OpenAPI operations,
 * keyed by operationId: each case builds the request through the public
 * service method, captures it through the fake transport and asserts the
 * method, the resolved path (including the /api/v1 prefix handling and the
 * query encoding), the Authorization header presence (or absence on public
 * endpoints), the body shape (JSON fields, multipart parts) and the decoded
 * response type from realistic OpenAPI-shaped payloads.
 */
final class ServicesTest extends TestCase
{
    private const WORKSPACE = 'wsp_01j5abcdefghjkmnpqrstvwxyz';

    private const CHAT = 'cht_01j5abcdefghjkmnpqrstvwxyz';

    private const AGENT = 'agt_01j5abcdefghjkmnpqrstvwxyz';

    private const TEAM = 'team_01j5abcdefghjkmnpqrstvwxyz';

    private const COMPLETION = 'cmpl_01j5abcdefghjkmnpqrstvwxyz';

    private const KEY = 'key_01j5abcdefghjkmnpqrstvwxyz';

    private const RECORD = 'igr_01j5abcdefghjkmnpqrstvwxyz';

    private const TOOL = 'send_email';

    private const TURN = 'cmpl_01j5abcdefghjkmnpqrstvwxyz';

    /**
     * @return array<string, array{method: string, path: string, auth: bool, retry: RetrySafety, invoke: \Closure, extra?: \Closure, throws?: class-string<\Throwable>}>
     */
    public static function operationsProvider(): array
    {
        $imageFile = tempnam(sys_get_temp_dir(), 'envai-test-').'.png';
        file_put_contents($imageFile, 'fake-png-bytes');

        $cases = [
            'sendChatMessage' => [
                'method' => 'POST',
                'path' => '/api/v1/atp/v3/chats/'.self::CHAT.'/messages',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): ChatMessageAccepted => $client->chats()->sendMessage(self::CHAT, new ChatMessageRequest('Summarize the last week of traffic')),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['message' => 'Summarize the last week of traffic']);
                    self::assertInstanceOf(ChatMessageAccepted::class, $result);
                    self::assertSame(self::TURN, $result->turnId);
                },
            ],
            'streamChatEvents' => [
                'method' => 'GET',
                'path' => '/api/v1/atp/v3/chats/'.self::CHAT.'/events',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): EventStreamConnection => $client->chats()->openEventStream(self::CHAT, 5),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    $stream = $transport->streamRequests[0];
                    self::assertNotNull($stream);
                    self::assertSame('/api/v1/atp/v3/chats/'.self::CHAT.'/events', parse_url($stream->uri, PHP_URL_PATH));
                    self::assertSame('after=5', parse_url($stream->uri, PHP_URL_QUERY));
                    self::assertSame(['text/event-stream'], $stream->headers['accept'] ?? null);
                    self::assertSame(['Bearer sk-test-secret'], $stream->headers['authorization'] ?? null);
                    self::assertSame($transport->connection, $result);
                    self::assertCount(0, $transport->requests, 'Streaming must not use the one-shot send() path.');
                },
            ],
            'cancelChatTurn' => [
                'method' => 'POST',
                'path' => '/api/v1/atp/v3/chats/'.self::CHAT.'/cancel',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): TurnAccepted => $client->chats()->cancel(self::CHAT),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertNull($transport->requests[0]->body);
                    self::assertInstanceOf(TurnAccepted::class, $result);
                    self::assertSame(self::TURN, $result->turnId);
                },
            ],
            'steerChatTurn' => [
                'method' => 'POST',
                'path' => '/api/v1/atp/v3/chats/'.self::CHAT.'/steer',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): TurnAccepted => $client->chats()->steer(self::CHAT, new SteerRequest('Prefer the cheaper provider', 'ag_01j5abcdefghjkmnpqrstvwxyz')),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['text' => 'Prefer the cheaper provider', 'target_agent_id' => 'ag_01j5abcdefghjkmnpqrstvwxyz']);
                    self::assertInstanceOf(TurnAccepted::class, $result);
                },
            ],
            'resolveChatTool' => [
                'method' => 'POST',
                'path' => '/api/v1/atp/v3/chats/'.self::CHAT.'/tool-output',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): null => $client->chats()->resolveToolOutput(self::CHAT, new ToolOutputRequest('tc_01j5abcdefghjkmnpqrstvwxyz', '{"ok":true}', true)),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['call_id' => 'tc_01j5abcdefghjkmnpqrstvwxyz', 'output' => '{"ok":true}', 'is_error' => true]);
                    self::assertNull($result);
                },
            ],
            'resolveChatApproval' => [
                'method' => 'POST',
                'path' => '/api/v1/atp/v3/chats/'.self::CHAT.'/approval',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): null => $client->chats()->submitApproval(self::CHAT, new ApprovalDecisionRequest('appr_01j5abcdefghjkmnpqrstvwxyz', true, 'Keep the budget under 10 dollars')),
                'extra' => static function (FakeTransport $transport, mixed $result, ?\Throwable $thrown): void {
                    self::jsonBody($transport->requests[0], ['approval_id' => 'appr_01j5abcdefghjkmnpqrstvwxyz', 'approve' => true, 'reason' => 'Keep the budget under 10 dollars']);
                    self::assertNull($result);
                },
                'throws' => ConflictException::class,
            ],
            'forkChat' => [
                'method' => 'POST',
                'path' => '/api/v1/atp/v3/chats/'.self::CHAT.'/fork',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): ChatForked => $client->chats()->fork(self::CHAT, new ForkChatRequest('cht_02j5abcdefghjkmnpqrstvwxyz')),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['destination_chat_id' => 'cht_02j5abcdefghjkmnpqrstvwxyz']);
                    self::assertInstanceOf(ChatForked::class, $result);
                    self::assertSame('cht_02j5abcdefghjkmnpqrstvwxyz', $result->destinationChatId);
                },
            ],
            'restoreChat' => [
                'method' => 'POST',
                'path' => '/api/v1/atp/v3/chats/'.self::CHAT.'/restore',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): RestoreChatAccepted => $client->chats()->restore(self::CHAT, new RestoreChatRequest([
                    new OrchestratorContextItem(OrchestratorContextKind::Compacted, 'Summary so far'),
                    new OrchestratorContextItem(OrchestratorContextKind::Assistant, 'Assistant message', self::TURN),
                ])),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], [
                        'orchestrator_context' => [
                            ['kind' => 'compacted', 'content' => 'Summary so far'],
                            ['kind' => 'assistant', 'content' => 'Assistant message', 'turn_id' => self::TURN],
                        ],
                    ]);
                    self::assertInstanceOf(RestoreChatAccepted::class, $result);
                    self::assertNull($result->turnId);
                },
            ],
            'getCapabilities' => [
                'method' => 'GET',
                'path' => '/api/v1/atp/v3/capabilities',
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): Capabilities => $client->capabilities()->getCapabilities(),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(Capabilities::class, $result);
                    self::assertSame(3, $result->protocolVersion);
                },
            ],
            'healthCheck' => [
                'method' => 'GET',
                'path' => '/api/v1/up',
                'auth' => false,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): HealthStatus => $client->capabilities()->healthCheck(),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(HealthStatus::class, $result);
                    self::assertSame('up', $result->status);
                },
            ],
            'getJwks' => [
                'method' => 'GET',
                'path' => '/.well-known/jwks.json',
                'auth' => false,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): JwksDocument => $client->capabilities()->jwks(),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertStringNotContainsString('/api/v1', $transport->requests[0]->uri, 'The JWKS endpoint must be resolved from the base URL origin, never under /api/v1.');
                    self::assertInstanceOf(JwksDocument::class, $result);
                    self::assertCount(1, $result->keys);
                },
            ],
            'createWorkspace' => [
                'method' => 'POST',
                'path' => '/api/v1/workspaces',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): Workspace => $client->workspaces()->create(new WorkspaceCreateRequest('Product analytics', new JsonObject(['team' => 'analytics']))),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['name' => 'Product analytics', 'metadata' => ['team' => 'analytics']]);
                    self::assertInstanceOf(Workspace::class, $result);
                },
            ],
            'listWorkspaces' => [
                'method' => 'GET',
                'path' => '/api/v1/workspaces',
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): ResourceCollection => $client->workspaces()->list(),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(ResourceCollection::class, $result);
                    self::assertSame(Workspace::class, $result->itemClass);
                    self::assertCount(1, $result->items);
                    self::assertInstanceOf(Workspace::class, $result->items[0]);
                },
            ],
            'getWorkspace' => [
                'method' => 'GET',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE,
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): Workspace => $client->workspaces()->get(self::WORKSPACE),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(Workspace::class, $result);
                    self::assertSame(self::WORKSPACE, $result->id);
                },
            ],
            'updateWorkspace' => [
                'method' => 'PUT',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE,
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): Workspace => $client->workspaces()->update(self::WORKSPACE, new WorkspaceUpdateRequest('Renamed')),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['name' => 'Renamed']);
                    self::assertInstanceOf(Workspace::class, $result);
                },
            ],
            'createUserTool' => [
                'method' => 'POST',
                'path' => '/api/v1/tools',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): UserTool => $client->tools()->create(new UserToolCreateRequest('send_email', ['type' => 'object', 'properties' => []], 'client', 'Sends an email', 60)),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], [
                        'name' => 'send_email',
                        'kind' => 'client',
                        'parameters' => ['type' => 'object', 'properties' => []],
                        'timeout_seconds' => 60,
                        'description' => 'Sends an email',
                    ]);
                    self::assertInstanceOf(UserTool::class, $result);
                },
            ],
            'listUserTools' => [
                'method' => 'GET',
                'path' => '/api/v1/tools',
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): ResourceCollection => $client->tools()->list(),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(ResourceCollection::class, $result);
                    self::assertSame(UserTool::class, $result->itemClass);
                },
            ],
            'getUserTool' => [
                'method' => 'GET',
                'path' => '/api/v1/tools/'.self::TOOL,
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): UserTool => $client->tools()->get(self::TOOL),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(UserTool::class, $result);
                    self::assertSame(self::TOOL, $result->name);
                },
            ],
            'updateUserTool' => [
                'method' => 'PUT',
                'path' => '/api/v1/tools/'.self::TOOL,
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): UserTool => $client->tools()->update(self::TOOL, UserToolUpdateRequest::timeoutSeconds(300)),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['timeout_seconds' => 300]);
                    self::assertInstanceOf(UserTool::class, $result);
                },
            ],
            'deleteUserTool' => [
                'method' => 'DELETE',
                'path' => '/api/v1/tools/'.self::TOOL,
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): null => $client->tools()->delete(self::TOOL),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertNull($result);
                },
            ],
            'createChat' => [
                'method' => 'POST',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE.'/chats',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): Chat => $client->chats()->createChat(self::WORKSPACE, new ChatCreateRequest(self::TEAM)),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['team_id' => self::TEAM]);
                    self::assertInstanceOf(Chat::class, $result);
                    self::assertSame(self::TEAM, $result->teamId);
                },
            ],
            'listChats' => [
                'method' => 'GET',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE.'/chats',
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): ResourceCollection => $client->chats()->listChats(self::WORKSPACE),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(ResourceCollection::class, $result);
                    self::assertSame(Chat::class, $result->itemClass);
                },
            ],
            'getChat' => [
                'method' => 'GET',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE.'/chats/'.self::CHAT,
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): Chat => $client->chats()->getChat(self::WORKSPACE, self::CHAT),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(Chat::class, $result);
                    self::assertSame(self::CHAT, $result->id);
                },
            ],
            'listCompletions' => [
                'method' => 'GET',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE.'/chats/'.self::CHAT.'/completions',
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): Page => $client->completions()->list(self::WORKSPACE, self::CHAT, new CompletionFilters(
                    status: CompletionStatus::Running,
                    modelOrchestrator: 'claude-sonnet-4',
                    startedAtFrom: '2026-08-01T00:00:00+00:00',
                    startedAtTo: '2026-08-28T00:00:00+00:00',
                    perPage: 20,
                )),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    parse_str((string) parse_url($transport->requests[0]->uri, PHP_URL_QUERY), $query);
                    self::assertSame('running', $query['status'] ?? null);
                    self::assertSame('claude-sonnet-4', $query['model_orchestrator'] ?? null);
                    self::assertSame('2026-08-01T00:00:00+00:00', $query['started_at']['from'] ?? null);
                    self::assertSame('2026-08-28T00:00:00+00:00', $query['started_at']['to'] ?? null);
                    self::assertSame('20', $query['per_page'] ?? null);
                    self::assertInstanceOf(Page::class, $result);
                    self::assertSame(Completion::class, $result->itemClass);
                    self::assertCount(1, $result->items);
                    self::assertInstanceOf(Completion::class, $result->items[0]);
                    self::assertSame(1, $result->meta->currentPage);
                    self::assertSame(42, $result->meta->total);
                },
            ],
            'getCompletion' => [
                'method' => 'GET',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE.'/chats/'.self::CHAT.'/completions/'.self::COMPLETION,
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): Completion => $client->completions()->get(self::WORKSPACE, self::CHAT, self::COMPLETION),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(Completion::class, $result);
                    self::assertSame(self::COMPLETION, $result->id);
                },
            ],
            'indexWorkspaceFiles' => [
                'method' => 'GET',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE.'/files',
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): WorkspaceFile => $client->workspaceFiles()->readOrList(self::WORKSPACE, '/wiki/guide.md'),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    parse_str((string) parse_url($transport->requests[0]->uri, PHP_URL_QUERY), $query);
                    self::assertSame('/wiki/guide.md', $query['path'] ?? null);
                    self::assertInstanceOf(WorkspaceFile::class, $result);
                    self::assertSame('wiki/guide.md', $result->path);
                },
            ],
            'createWorkspaceFile' => [
                'method' => 'POST',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE.'/files',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): WorkspaceFile => $client->workspaceFiles()->create(self::WORKSPACE, new WorkspaceFileRequest('/wiki/guide.md', '# Guide')),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['path' => '/wiki/guide.md', 'content' => '# Guide']);
                    self::assertInstanceOf(WorkspaceFile::class, $result);
                },
            ],
            'updateWorkspaceFile' => [
                'method' => 'PUT',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE.'/files',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): WorkspaceFile => $client->workspaceFiles()->update(self::WORKSPACE, new WorkspaceFileRequest('/wiki/guide.md', '# Updated')),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['path' => '/wiki/guide.md', 'content' => '# Updated']);
                    self::assertInstanceOf(WorkspaceFile::class, $result);
                },
            ],
            'deleteWorkspaceFile' => [
                'method' => 'DELETE',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE.'/files',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): null => $client->workspaceFiles()->delete(self::WORKSPACE, '/wiki', true),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    parse_str((string) parse_url($transport->requests[0]->uri, PHP_URL_QUERY), $query);
                    self::assertSame('/wiki', $query['path'] ?? null);
                    self::assertSame('1', $query['recursive'] ?? null);
                    self::assertNull($result);
                },
            ],
            'downloadWorkspaceFile' => [
                'method' => 'GET',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE.'/files/download',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): DownloadResult => $client->workspaceFiles()->download(self::WORKSPACE, '/wiki/guide.md'),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    parse_str((string) parse_url($transport->requests[0]->uri, PHP_URL_QUERY), $query);
                    self::assertSame('/wiki/guide.md', $query['path'] ?? null);
                    self::assertInstanceOf(DownloadResult::class, $result);
                    self::assertSame('text/markdown', $result->mediaType);
                    self::assertSame('guide.md', $result->filename);
                    self::assertSame(2048, $result->contentLength);
                },
            ],
            'convertWorkspaceFileToPdf' => [
                'method' => 'POST',
                'path' => '/api/v1/workspaces/'.self::WORKSPACE.'/files/convert-to-pdf',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): DownloadResult => $client->workspaceFiles()->convertToPdf(self::WORKSPACE, new ConvertToPdfRequest('/wiki/guide.md')),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['path' => '/wiki/guide.md']);
                    self::assertInstanceOf(DownloadResult::class, $result);
                    self::assertSame('application/pdf', $result->mediaType);
                },
            ],
            'createAgent' => [
                'method' => 'POST',
                'path' => '/api/v1/agents',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): Agent => $client->agents()->create(new AgentCreateRequest('Planner', 'You plan carefully.', AgentMode::Primary, config: ['temperature' => 0.2])),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], [
                        'name' => 'Planner',
                        'system_prompt' => 'You plan carefully.',
                        'mode' => 'primary',
                        'can_write_knowledge' => false,
                        'can_write_skills' => false,
                        'config' => ['temperature' => 0.2],
                    ]);
                    self::assertInstanceOf(Agent::class, $result);
                },
            ],
            'listAgents' => [
                'method' => 'GET',
                'path' => '/api/v1/agents',
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): ResourceCollection => $client->agents()->list(),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(ResourceCollection::class, $result);
                    self::assertSame(Agent::class, $result->itemClass);
                },
            ],
            'getAgent' => [
                'method' => 'GET',
                'path' => '/api/v1/agents/'.self::AGENT,
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): Agent => $client->agents()->get(self::AGENT),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(Agent::class, $result);
                    self::assertSame(self::AGENT, $result->id);
                },
            ],
            'updateAgent' => [
                'method' => 'PUT',
                'path' => '/api/v1/agents/'.self::AGENT,
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): Agent => $client->agents()->update(self::AGENT, AgentUpdateRequest::toolsEnabled([new AgentTool('read')])),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['tools_enabled' => [['name' => 'read']]]);
                    self::assertInstanceOf(Agent::class, $result);
                },
            ],
            'deleteAgent' => [
                'method' => 'DELETE',
                'path' => '/api/v1/agents/'.self::AGENT,
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): null => $client->agents()->delete(self::AGENT),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertNull($result);
                },
            ],
            'createTeam' => [
                'method' => 'POST',
                'path' => '/api/v1/teams',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): Team => $client->teams()->create(new TeamCreateRequest('Research', self::AGENT, null, [new TeamMemberCreateRequest('agt_02j5abcdefghjkmnpqrstvwxyz', 'researcher')])),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], [
                        'name' => 'Research',
                        'orchestrator_id' => self::AGENT,
                        'members' => [['agent_definition_id' => 'agt_02j5abcdefghjkmnpqrstvwxyz', 'role' => 'researcher']],
                    ]);
                    self::assertInstanceOf(Team::class, $result);
                },
            ],
            'listTeams' => [
                'method' => 'GET',
                'path' => '/api/v1/teams',
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): ResourceCollection => $client->teams()->list(),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(ResourceCollection::class, $result);
                    self::assertSame(Team::class, $result->itemClass);
                },
            ],
            'getTeam' => [
                'method' => 'GET',
                'path' => '/api/v1/teams/'.self::TEAM,
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): Team => $client->teams()->get(self::TEAM),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(Team::class, $result);
                    self::assertSame(self::TEAM, $result->id);
                },
            ],
            'updateTeam' => [
                'method' => 'PUT',
                'path' => '/api/v1/teams/'.self::TEAM,
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): Team => $client->teams()->update(self::TEAM, TeamUpdateRequest::name('Renamed team')),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['name' => 'Renamed team']);
                    self::assertInstanceOf(Team::class, $result);
                },
            ],
            'deleteTeam' => [
                'method' => 'DELETE',
                'path' => '/api/v1/teams/'.self::TEAM,
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): null => $client->teams()->delete(self::TEAM),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertNull($result);
                },
            ],
            'listModels' => [
                'method' => 'GET',
                'path' => '/api/v1/models',
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): ModelList => $client->models()->listModels(),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(ModelList::class, $result);
                    self::assertSame('list', $result->object);
                    self::assertCount(1, $result->data);
                },
            ],
            'createApiKey' => [
                'method' => 'POST',
                'path' => '/api/v1/api-keys',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): ApiKeyCreated => $client->apiKeys()->create(new ApiKeyCreateRequest('ci-deploy', [ApiKeyScope::CompletionsWrite], ApiKeyExpiry::D30D)),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], ['name' => 'ci-deploy', 'scopes' => ['completions:write'], 'expires_at' => '30d']);
                    self::assertInstanceOf(ApiKeyCreated::class, $result);
                    self::assertSame('envai_sk_testtoken', $result->token->reveal());
                },
            ],
            'listApiKeys' => [
                'method' => 'GET',
                'path' => '/api/v1/api-keys',
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): ResourceCollection => $client->apiKeys()->list(),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(ResourceCollection::class, $result);
                    self::assertSame(ApiKey::class, $result->itemClass);
                },
            ],
            'deleteApiKey' => [
                'method' => 'DELETE',
                'path' => '/api/v1/api-keys/'.self::KEY,
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): null => $client->apiKeys()->delete(self::KEY),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertNull($result);
                },
            ],
            'testGenerateImage' => [
                'method' => 'POST',
                'path' => '/api/v1/tests/images/generate',
                'auth' => false,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): TestImageGenerateResult => $client->images()->testGenerateImage(new TestImageGenerateRequest([$imageFile, $imageFile], 'A beautiful sunset', 'google/nano-banana-2')),
                'extra' => static function (FakeTransport $transport, mixed $result) use ($imageFile): void {
                    $body = $transport->requests[0]->body;
                    self::assertInstanceOf(MultipartBody::class, $body);
                    self::assertSame('multipart/form-data; boundary='.$body->boundary, $transport->requests[0]->headers['content-type'][0] ?? null);
                    self::assertInstanceOf(MultipartField::class, $body->parts[0]);
                    self::assertSame('prompt', $body->parts[0]->name);
                    self::assertSame('A beautiful sunset', $body->parts[0]->value);
                    self::assertInstanceOf(MultipartField::class, $body->parts[1]);
                    self::assertSame('model_code', $body->parts[1]->name);
                    self::assertSame('google/nano-banana-2', $body->parts[1]->value);
                    self::assertCount(4, $body->parts);
                    self::assertInstanceOf(MultipartFile::class, $body->parts[2]);
                    self::assertSame('files[]', $body->parts[2]->name);
                    self::assertSame(basename($imageFile), $body->parts[2]->filename);
                    self::assertInstanceOf(MultipartFile::class, $body->parts[3]);
                    self::assertSame('files[]', $body->parts[3]->name);
                    self::assertInstanceOf(TestImageGenerateResult::class, $result);
                },
            ],
            'createImageGeneration' => [
                'method' => 'POST',
                'path' => '/api/v1/images/generations',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): ImageGenerationAcceptedRecord => $client->images()->generate(new ImageGenerationRequest(
                    'google/nano-banana-2',
                    'A beautiful sunset',
                    'https://hooks.example.com/cb',
                    resolution: '1k',
                    aspectRatio: '16:9',
                    format: 'png',
                )),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::jsonBody($transport->requests[0], [
                        'model' => 'google/nano-banana-2',
                        'prompt' => 'A beautiful sunset',
                        'webhook_url' => 'https://hooks.example.com/cb',
                        'resolution' => '1k',
                        'aspect_ratio' => '16:9',
                        'format' => 'png',
                    ]);
                    self::assertInstanceOf(ImageGenerationAcceptedRecord::class, $result);
                    self::assertSame(self::RECORD, $result->id);
                },
            ],
            'getImageGeneration' => [
                'method' => 'GET',
                'path' => '/api/v1/images/generations/'.self::RECORD,
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): ImageGenerationStatusRecord => $client->images()->getGeneration(self::RECORD),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(ImageGenerationStatusRecord::class, $result);
                    self::assertSame(self::RECORD, $result->id);
                },
            ],
            'uploadImage' => [
                'method' => 'POST',
                'path' => '/api/v1/images/uploads',
                'auth' => true,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): ImageUploadRecord => $client->images()->upload($imageFile, 'image/png'),
                'extra' => static function (FakeTransport $transport, mixed $result) use ($imageFile): void {
                    $body = $transport->requests[0]->body;
                    self::assertInstanceOf(MultipartBody::class, $body);
                    self::assertCount(1, $body->parts);
                    self::assertInstanceOf(MultipartFile::class, $body->parts[0]);
                    self::assertSame('file', $body->parts[0]->name);
                    self::assertSame('image/png', $body->parts[0]->mimeType);
                    self::assertSame(basename($imageFile), $body->parts[0]->filename);
                    self::assertInstanceOf(ImageUploadRecord::class, $result);
                },
            ],
            'listImageModels' => [
                'method' => 'GET',
                'path' => '/api/v1/images/models',
                'auth' => true,
                'retry' => RetrySafety::Safe,
                'invoke' => static fn (EnvaiClient $client): ResourceCollection => $client->images()->listImageModels(),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    self::assertInstanceOf(ResourceCollection::class, $result);
                    self::assertSame(ModelCatalogEntry::class, $result->itemClass);
                    self::assertInstanceOf(ModelCatalogEntry::class, $result->items[0]);
                    self::assertSame('google/nano-banana-2', $result->items[0]->model);
                },
            ],
            'downloadArtifact' => [
                'method' => 'GET',
                'path' => '/api/v1/artifacts/download',
                'auth' => false,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): DownloadResult => $client->images()->downloadGenerationArtifact('igr_01ARZ3NDEKTSV4RRFFQ69G5FAV', 'art_01ARZ3NDEKTSV4RRFFQ69G5FAV', '6f8d2c4a9b1e5f7a', 1786320000),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    parse_str((string) parse_url($transport->requests[0]->uri, PHP_URL_QUERY), $query);
                    self::assertSame('igr_01ARZ3NDEKTSV4RRFFQ69G5FAV', $query['generation'] ?? null);
                    self::assertSame('art_01ARZ3NDEKTSV4RRFFQ69G5FAV', $query['artifact'] ?? null);
                    self::assertArrayNotHasKey('path', $query);
                    self::assertSame('6f8d2c4a9b1e5f7a', $query['signature'] ?? null);
                    self::assertSame('1786320000', $query['expires'] ?? null);
                    self::assertInstanceOf(DownloadResult::class, $result);
                    self::assertSame('image/png', $result->mediaType);
                },
            ],
            'handleProviderWebhook' => [
                'method' => 'POST',
                'path' => '/api/v1/webhooks/providers/replicate',
                'auth' => false,
                'retry' => RetrySafety::NeverRetry,
                'invoke' => static fn (EnvaiClient $client): WebhookOk => $client->images()->submitProviderWebhook('replicate', new ProviderWebhookPayload(new JsonObject(['id' => 'job-matched', 'status' => 'processing'])), '0192f3a4-6b1c'),
                'extra' => static function (FakeTransport $transport, mixed $result): void {
                    parse_str((string) parse_url($transport->requests[0]->uri, PHP_URL_QUERY), $query);
                    self::assertSame('0192f3a4-6b1c', $query['callback_token'] ?? null);
                    self::jsonBody($transport->requests[0], ['id' => 'job-matched', 'status' => 'processing']);
                    self::assertInstanceOf(WebhookOk::class, $result);
                    self::assertSame('ok', $result->status);
                },
            ],
        ];

        foreach ($cases as $operationId => $case) {
            $cases[$operationId] = [['operationId' => $operationId] + $case];
        }

        return $cases;
    }

    /**
     * @param  array{operationId: string, method: string, path: string, auth: bool, retry: RetrySafety, invoke: \Closure, extra?: \Closure, throws?: class-string<\Throwable>}  $case
     */
    #[DataProvider('operationsProvider')]
    public function test_operation(array $case): void
    {
        $operationId = $case['operationId'];

        $transport = new FakeTransport;
        $client = self::client($transport);
        $transport->queueResponse(self::responseFor($operationId));

        $result = null;
        $thrown = null;

        try {
            $result = ($case['invoke'])($client);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        if ($operationId === 'streamChatEvents') {
            $this->assertCount(1, $transport->streamRequests, 'openEventStream must open exactly one stream.');
        } else {
            $request = $transport->requests[0];
            $this->assertInstanceOf(HttpRequest::class, $request, sprintf('Operation %s must send exactly one HttpRequest.', $operationId));
            $this->assertSame($case['method'], $request->method, sprintf('Method mismatch for %s.', $operationId));
            $this->assertSame($case['path'], self::pathOf($request->uri), sprintf('Path mismatch for %s.', $operationId));
            $this->assertSame($case['retry'], $request->retrySafety, sprintf('Retry safety mismatch for %s.', $operationId));

            if ($case['auth']) {
                $this->assertSame(['Bearer sk-test-secret'], $request->headers['authorization'] ?? null, sprintf('Missing Authorization header for %s.', $operationId));
            } else {
                $this->assertArrayNotHasKey('authorization', $request->headers, sprintf('Public operation %s must not carry an Authorization header.', $operationId));
            }
        }

        ($case['extra'] ?? static function (mixed ...$ignored): void {})(...[$transport, $result, $thrown]);

        if (isset($case['throws'])) {
            $this->assertInstanceOf($case['throws'], $thrown, sprintf('Operation %s must map the alpha stub 409 to %s.', $operationId, $case['throws']));
        } else {
            $this->assertNull($thrown, sprintf('Operation %s must not throw; got %s.', $operationId, $thrown?->getMessage() ?? 'no message'));
        }
    }

    private static function client(FakeTransport $transport): EnvaiClient
    {
        $config = new EnvaiConfig(
            baseUrl: 'https://envai.example.com',
            apiKey: new SensitiveString('sk-test-secret'),
        );

        return new EnvaiClient($config, $transport);
    }

    private static function responseFor(string $operationId): HttpResponse
    {
        return match ($operationId) {
            'sendChatMessage', 'cancelChatTurn', 'steerChatTurn' => self::topLevel(202, self::turnAcceptedPayload()),
            'resolveChatApproval' => new HttpResponse(409, [], json_encode(['error' => ['type' => 'conflict', 'message' => 'Approval resolution is not implemented.', 'details' => null]], JSON_THROW_ON_ERROR)),
            'forkChat' => self::topLevel(200, self::chatForkedPayload()),
            'restoreChat' => self::topLevel(200, self::restoreAcceptedPayload()),
            'getCapabilities' => self::topLevel(200, self::capabilitiesPayload()),
            'healthCheck' => self::json(200, self::healthPayload()),
            'getJwks' => self::topLevel(200, self::jwksPayload()),
            'createWorkspace' => self::json(201, self::workspacePayload()),
            'listWorkspaces' => self::collection(200, [self::workspacePayload()]),
            'getWorkspace', 'updateWorkspace' => self::json(200, self::workspacePayload()),
            'createUserTool' => self::json(201, self::userToolPayload()),
            'listUserTools' => self::collection(200, [self::userToolPayload()]),
            'getUserTool', 'updateUserTool' => self::json(200, self::userToolPayload()),
            'createChat' => self::json(201, self::chatPayload()),
            'listChats' => self::collection(200, [self::chatPayload()]),
            'getChat' => self::json(200, self::chatPayload()),
            'listCompletions' => self::page(self::completionPayload()),
            'getCompletion' => self::json(200, self::completionPayload()),
            'indexWorkspaceFiles' => self::json(200, self::workspaceFilePayload()),
            'createWorkspaceFile' => self::json(201, self::workspaceFilePayload()),
            'updateWorkspaceFile' => self::json(200, self::workspaceFilePayload()),
            'downloadWorkspaceFile' => new HttpResponse(200, [
                'content-type' => ['text/markdown; charset=utf-8'],
                'content-disposition' => ['attachment; filename="guide.md"'],
                'content-length' => ['2048'],
                'x-content-type-options' => ['nosniff'],
            ]),
            'convertWorkspaceFileToPdf' => new HttpResponse(200, [
                'content-type' => ['application/pdf'],
                'content-disposition' => ['attachment; filename="guide.pdf"'],
                'content-length' => ['4096'],
            ]),
            'createAgent' => self::json(201, self::agentPayload()),
            'listAgents' => self::collection(200, [self::agentPayload()]),
            'getAgent', 'updateAgent' => self::json(200, self::agentPayload()),
            'createTeam' => self::json(201, self::teamPayload()),
            'listTeams' => self::collection(200, [self::teamPayload()]),
            'getTeam', 'updateTeam' => self::json(200, self::teamPayload()),
            'listModels' => self::topLevel(200, self::modelListPayload()),
            'createApiKey' => self::json(201, self::apiKeyCreatedPayload()),
            'listApiKeys' => self::collection(200, [self::apiKeyPayload()]),
            'testGenerateImage' => self::json(200, self::imageTestResultPayload()),
            'createImageGeneration' => self::json(202, self::imageAcceptedPayload()),
            'getImageGeneration' => self::json(200, self::imageStatusPayload()),
            'uploadImage' => self::json(201, self::imageUploadPayload()),
            'listImageModels' => self::collection(200, [self::modelCatalogPayload()]),
            'downloadArtifact' => new HttpResponse(200, [
                'content-type' => ['image/png'],
                'content-disposition' => ['attachment; filename="01ARZ3NDEK_0.jpeg"'],
            ]),
            'handleProviderWebhook' => self::topLevel(200, ['status' => 'ok']),
            'resolveChatTool', 'deleteUserTool', 'deleteWorkspaceFile', 'deleteAgent', 'deleteTeam', 'deleteApiKey' => new HttpResponse(204),
            'streamChatEvents' => new HttpResponse(200, ['content-type' => ['text/event-stream']]),
            default => throw new \LogicException('No scripted response for operation '.$operationId.'.'),
        };
    }

    private static function json(int $status, array $payload): HttpResponse
    {
        return new HttpResponse($status, ['content-type' => ['application/json']], json_encode(['data' => $payload], JSON_THROW_ON_ERROR));
    }

    private static function collection(int $status, array $items): HttpResponse
    {
        return new HttpResponse($status, ['content-type' => ['application/json']], json_encode(['data' => $items], JSON_THROW_ON_ERROR));
    }

    private static function topLevel(int $status, array $payload): HttpResponse
    {
        return new HttpResponse($status, ['content-type' => ['application/json']], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private static function page(array $item): HttpResponse
    {
        $payload = [
            'data' => [$item],
            'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => null],
            'meta' => ['current_page' => 1, 'from' => 1, 'last_page' => 1, 'per_page' => 15, 'to' => 1, 'total' => 42],
        ];

        return self::topLevel(200, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private static function workspacePayload(): array
    {
        return [
            'id' => self::WORKSPACE,
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'Product analytics',
            'metadata' => [],
            'created_at' => '2026-08-01T09:00:00+00:00',
            'updated_at' => '2026-08-01T09:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function userToolPayload(): array
    {
        return [
            'id' => 'twl_01j5abcdefghjkmnpqrstvwxyz',
            'name' => self::TOOL,
            'kind' => 'client',
            'description' => 'Sends an email',
            'parameters' => ['type' => 'object', 'properties' => []],
            'timeout_seconds' => 60,
            'created_at' => '2026-08-01T09:00:00+00:00',
            'updated_at' => '2026-08-01T09:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function chatPayload(): array
    {
        return [
            'id' => self::CHAT,
            'workspace_id' => self::WORKSPACE,
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'team_id' => self::TEAM,
            'created_at' => '2026-08-01T09:00:00+00:00',
            'updated_at' => '2026-08-01T09:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function completionPayload(): array
    {
        return [
            'id' => self::COMPLETION,
            'workspace_id' => self::WORKSPACE,
            'chat_id' => self::CHAT,
            'team_id' => self::TEAM,
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'model_orchestrator' => 'claude-sonnet-4',
            'models_subagents' => [],
            'started_at' => '2026-08-01T09:00:00+00:00',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 450],
            'cost_usd' => 0.42,
            'status' => 'succeeded',
            'finish_reason' => 'stop',
            'finished_at' => '2026-08-01T09:01:00+00:00',
            'duration_ms' => 60000,
            'content_hash' => 'abc123',
            'created_at' => '2026-08-01T09:00:00+00:00',
            'updated_at' => '2026-08-01T09:01:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function workspaceFilePayload(): array
    {
        return [
            'path' => 'wiki/guide.md',
            'name' => 'guide.md',
            'type' => 'file',
            'size' => 2048,
            'modified_at' => '2026-08-17T14:01:00+00:00',
            'mime_type' => 'text/markdown',
            'content' => '# Guide',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function agentPayload(): array
    {
        return [
            'id' => self::AGENT,
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'Planner',
            'description' => null,
            'system_prompt' => 'You plan carefully.',
            'model' => null,
            'mode' => 'primary',
            'tools_enabled' => [],
            'external_tools_enabled' => [],
            'permissions' => [],
            'can_write_knowledge' => false,
            'can_write_skills' => false,
            'config' => ['temperature' => 0.2],
            'created_at' => '2026-08-01T09:00:00+00:00',
            'updated_at' => '2026-08-01T09:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function teamPayload(): array
    {
        return [
            'id' => self::TEAM,
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'Research',
            'description' => null,
            'orchestrator_id' => self::AGENT,
            'members' => [],
            'created_at' => '2026-08-01T09:00:00+00:00',
            'updated_at' => '2026-08-01T09:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function apiKeyPayload(): array
    {
        return [
            'id' => self::KEY,
            'name' => 'ci-deploy',
            'scopes' => ['completions:write'],
            'expires_at' => '2026-09-27T00:00:00+00:00',
            'revoked_at' => null,
            'created_at' => '2026-08-28T00:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function apiKeyCreatedPayload(): array
    {
        return self::apiKeyPayload() + ['token' => 'envai_sk_testtoken'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function turnAcceptedPayload(): array
    {
        return ['status' => 'accepted', 'turn_id' => self::TURN];
    }

    /**
     * @return array<string, mixed>
     */
    private static function chatForkedPayload(): array
    {
        return ['source_chat_id' => self::CHAT, 'destination_chat_id' => 'cht_02j5abcdefghjkmnpqrstvwxyz'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function restoreAcceptedPayload(): array
    {
        return ['status' => 'restored', 'turn_id' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private static function capabilitiesPayload(): array
    {
        return [
            'protocol_version' => 3, 'contract_version' => 3, 'supported_contract_versions' => [2, 3],
            'families' => ['turn', 'agent'],
            'event_types' => ['ping', 'turn.started'],
            'tool_kinds' => ['server', 'client'],
            'features' => ['replay', 'fork', 'restore', 'steer'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function healthPayload(): array
    {
        return ['status' => 'up', 'timestamp' => '2026-03-01T12:00:00+00:00', 'app' => 'Laravel', 'env' => 'local'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jwksPayload(): array
    {
        return [
            'keys' => [
                ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'base64-x', 'kid' => 'sig_01j5abcdefghjkmnpqrstvwxyz', 'use' => 'sig', 'alg' => 'EdDSA'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function modelListPayload(): array
    {
        return [
            'object' => 'list',
            'data' => [
                ['id' => 'claude-sonnet-4', 'object' => 'model', 'created' => 1720000000, 'owned_by' => 'anthropic', 'type' => 'llm', 'deprecated' => false, 'sunset_at' => null],
            ],
            'defaults' => ['orchestrator' => 'claude-sonnet-4', 'subagent' => 'claude-haiku-3.5'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function imageAcceptedPayload(): array
    {
        return [
            'id' => self::RECORD,
            'status' => 'accepted',
            'provider' => 'replicate',
            'initial_provider' => 'replicate',
            'routed' => false,
            'model' => 'google/nano-banana-2',
            'webhook_url' => 'https://hooks.example.com/cb',
            'status_url' => 'https://envai.example.com/api/v1/images/generations/'.self::RECORD,
            'created_at' => '2026-08-28T10:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function imageStatusPayload(): array
    {
        return [
            'id' => self::RECORD,
            'status' => 'processing',
            'provider' => 'replicate',
            'initial_provider' => 'replicate',
            'routed' => false,
            'model' => 'google/nano-banana-2',
            'created_at' => '2026-08-28T10:00:00+00:00',
            'submitted_at' => '2026-08-28T10:00:01+00:00',
            'completed_at' => null,
            'expires_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function imageUploadPayload(): array
    {
        return [
            'id' => 'upl_01j5abcdefghjkmnpqrstvwxyz',
            'mime_type' => 'image/png',
            'size' => 4096,
            'original_filename' => 'photo.png',
            'expires_at' => '2026-08-29T10:00:00+00:00',
            'created_at' => '2026-08-28T10:00:00+00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function modelCatalogPayload(): array
    {
        return [
            'model' => 'google/nano-banana-2',
            'providers' => ['vertex', 'fal', 'replicate'],
            'price_classes' => [
                ['key' => 'resolution_1k', 'label' => '1K resolution', 'providers_count' => 3, 'average_price' => ['amount' => '0.064833', 'currency' => 'USD']],
            ],
            'model_settings' => [
                ['key' => 'quality_mode', 'type' => 'string', 'providers' => ['fal', 'replicate']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function imageTestResultPayload(): array
    {
        return [
            'status' => 'received',
            'prompt' => 'A beautiful sunset',
            'model_code' => 'google/nano-banana-2',
            'files_count' => 2,
            'files' => [
                ['original_name' => 'photo1.jpg', 'mime_type' => 'image/jpeg', 'size' => 1024],
                ['original_name' => 'photo2.png', 'mime_type' => 'image/png', 'size' => 2048],
            ],
            'received_at' => '2026-08-28T10:00:00+00:00',
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private static function jsonBody(HttpRequest $request, array $expected): void
    {
        TestCase::assertIsString($request->body);
        $decoded = json_decode($request->body, true, 512, JSON_THROW_ON_ERROR);
        TestCase::assertSame($expected, $decoded);
    }

    private static function pathOf(string $uri): string
    {
        return parse_url($uri, PHP_URL_PATH) ?: '';
    }
}
