<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Contracts\EventStreamTransport;
use Digitalygo\Envai\Exceptions\ContractCompatibilityException;
use Digitalygo\Envai\Exceptions\UnsupportedCapabilityException;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Http\StreamRequest;
use Digitalygo\Envai\Models\Chat\ApprovalDecisionRequest;
use Digitalygo\Envai\Models\Chat\Chat;
use Digitalygo\Envai\Models\Chat\ChatCreateRequest;
use Digitalygo\Envai\Models\Chat\ChatForked;
use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use Digitalygo\Envai\Models\Chat\DelegationAccepted;
use Digitalygo\Envai\Models\Chat\DelegationRequest;
use Digitalygo\Envai\Models\Chat\DeliveryAccepted;
use Digitalygo\Envai\Models\Chat\DeliveryRequest;
use Digitalygo\Envai\Models\Chat\ForkChatRequest;
use Digitalygo\Envai\Models\Chat\PhaseClassificationRequest;
use Digitalygo\Envai\Models\Chat\PhaseClassificationResult;
use Digitalygo\Envai\Models\Chat\RestoreChatAccepted;
use Digitalygo\Envai\Models\Chat\RestoreChatRequest;
use Digitalygo\Envai\Models\Chat\SteerRequest;
use Digitalygo\Envai\Models\Chat\TaskCancelled;
use Digitalygo\Envai\Models\Chat\TaskSnapshot;
use Digitalygo\Envai\Models\Chat\ToolOutputRequest;
use Digitalygo\Envai\Models\Chat\TurnAccepted;
use Digitalygo\Envai\Models\Common\ResourceCollection;
use Digitalygo\Envai\Models\System\Capabilities;

/**
 * Execution plane chat operations plus the chat control plane surface.
 *
 * Every chat mutation is ambiguous and never retried automatically; the
 * multiplexed event stream is opened through the injected
 * EventStreamTransport and carries the caller's credentials in the
 * Authorization header (StreamRequest has no auth mode of its own).
 *
 * submitApproval() maps the alpha stub behavior: the endpoint answers 409
 * until approval resolution is supported, which surfaces as a
 * ConflictException.
 */
final readonly class ChatsService
{
    private TypedRequester $requests;

    public function __construct(
        RequestExecutor $executor,
        UriResolver $uris,
        private EventStreamTransport $streams,
        private SensitiveString $apiKey,
        int $connectTimeoutSeconds = 10,
        int $requestTimeoutSeconds = 60,
        private ?int $streamTimeoutSeconds = null,
        private int $contractVersion = 3,
    ) {
        $this->requests = new TypedRequester($executor, $uris, $connectTimeoutSeconds, $requestTimeoutSeconds);
    }

    public function requireCapability(string $feature): void
    {
        $capabilities = $this->requests->request('GET', '/atp/v3/capabilities', [], null, Capabilities::class, EnvelopeMode::TopLevel);
        assert($capabilities instanceof Capabilities);
        if ($this->contractVersion !== 3 || ! $capabilities->supports($feature)) {
            throw new UnsupportedCapabilityException('Server does not advertise '.$feature.'.');
        }
    }

    public function deliver(string $chatId, DeliveryRequest $request): DeliveryAccepted
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/deliveries', ['chat_id' => $chatId]),
            [],
            $request->toArray(),
            DeliveryAccepted::class,
            EnvelopeMode::TopLevel,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var DeliveryAccepted $result */
        return $result;
    }

    public function delegate(string $chatId, DelegationRequest $request): DelegationAccepted
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/delegations', ['chat_id' => $chatId]),
            [],
            $request->toArray(),
            DelegationAccepted::class,
            EnvelopeMode::TopLevel,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var DelegationAccepted $result */
        return $result;
    }

    public function getTask(string $chatId, string $taskId): TaskSnapshot
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/tasks/{task_id}', ['chat_id' => $chatId, 'task_id' => $taskId]),
            [],
            null,
            TaskSnapshot::class,
            EnvelopeMode::TopLevel,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var TaskSnapshot $result */
        return $result;
    }

    public function cancelTask(string $chatId, string $taskId, bool $subtree = false): TaskCancelled
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/tasks/{task_id}/cancel', ['chat_id' => $chatId, 'task_id' => $taskId]),
            [],
            ['subtree' => $subtree],
            TaskCancelled::class,
            EnvelopeMode::TopLevel,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var TaskCancelled $result */
        return $result;
    }

    public function classifyPhase(string $chatId, PhaseClassificationRequest $request): PhaseClassificationResult
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/classify-phase', ['chat_id' => $chatId]),
            [],
            $request->toArray(),
            PhaseClassificationResult::class,
            EnvelopeMode::TopLevel,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var PhaseClassificationResult $result */
        return $result;
    }

    /**
     * Sends a user message and starts a turn (POST
     * /chats/{chat_id}/messages, HTTP 202).
     */
    public function sendMessage(string $chatId, ChatMessageRequest $request, ?string $idempotencyKey = null): ChatMessageAccepted
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/messages', ['chat_id' => $chatId]),
            [],
            $request->toArray(),
            ChatMessageAccepted::class,
            EnvelopeMode::TopLevel,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
            idempotencyKey: $idempotencyKey,
        );

        /** @var ChatMessageAccepted $result */
        return $result;
    }

    /**
     * Opens the multiplexed SSE event stream of a chat, replaying every
     * retained event strictly after the given sequence number (GET
     * /chats/{chat_id}/events?after=...). The connection is owned by the
     * caller, who must close() it when done.
     */
    public function openLegacyEventStream(string $chatId, int $after = 0, ?CancellationToken $token = null): EventStreamConnection
    {
        if ($this->contractVersion !== 2) {
            throw new ContractCompatibilityException('Legacy replay requires explicit contract 2 read-only mode.');
        }

        return $this->openEventStream($chatId, $after, $token);
    }

    public function openEventStream(string $chatId, int $after = 0, ?CancellationToken $token = null): EventStreamConnection
    {
        $path = $this->requests->resolve('/atp/v3/chats/{chat_id}/events', ['chat_id' => $chatId]);

        $streamRequest = new StreamRequest(
            uri: $this->requests->url($path, ['after' => $after]),
            headers: [
                'accept' => 'text/event-stream',
                'authorization' => 'Bearer '.$this->apiKey->reveal(),
            ],
            connectTimeoutSeconds: 10,
            responseHeaderTimeoutSeconds: 30,
            streamTimeoutSeconds: $this->streamTimeoutSeconds,
            cancellationToken: $token,
        );

        return $this->streams->open($streamRequest);
    }

    /**
     * Cancels the active turn (POST /chats/{chat_id}/cancel, HTTP 202).
     */
    public function cancel(string $chatId): TurnAccepted
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/cancel', ['chat_id' => $chatId]),
            [],
            null,
            TurnAccepted::class,
            EnvelopeMode::TopLevel,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var TurnAccepted $result */
        return $result;
    }

    /**
     * Records guidance for the active turn (POST /chats/{chat_id}/steer,
     * HTTP 202).
     */
    public function steer(string $chatId, SteerRequest $request): TurnAccepted
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/steer', ['chat_id' => $chatId]),
            [],
            $request->toArray(),
            TurnAccepted::class,
            EnvelopeMode::TopLevel,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var TurnAccepted $result */
        return $result;
    }

    /**
     * Resolves a pending client tool call with the client output (POST
     * /chats/{chat_id}/tool-output, HTTP 204).
     */
    public function resolveToolOutput(string $chatId, ToolOutputRequest $request): void
    {
        $this->requests->noContent(
            'POST',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/tool-output', ['chat_id' => $chatId]),
            [],
            $request->toArray(),
        );
    }

    /**
     * Resolves a pending approval with the user's decision (POST
     * /chats/{chat_id}/approval). The alpha server answers 409 because the
     * endpoint is a stub, mapped to ConflictException.
     */
    public function submitApproval(string $chatId, ApprovalDecisionRequest $request): void
    {
        $this->requests->noContent(
            'POST',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/approval', ['chat_id' => $chatId]),
            [],
            $request->toArray(),
        );
    }

    /**
     * Branches the source chat into an empty destination chat (POST
     * /chats/{chat_id}/fork).
     */
    public function fork(string $chatId, ForkChatRequest $request): ChatForked
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/fork', ['chat_id' => $chatId]),
            [],
            $request->toArray(),
            ChatForked::class,
            EnvelopeMode::TopLevel,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var ChatForked $result */
        return $result;
    }

    /**
     * Restores a chat from client held orchestrator context (POST
     * /chats/{chat_id}/restore). Does not open a turn, so turn_id is null.
     */
    public function restore(string $chatId, RestoreChatRequest $request): RestoreChatAccepted
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/atp/v3/chats/{chat_id}/restore', ['chat_id' => $chatId]),
            [],
            $request->toArray(),
            RestoreChatAccepted::class,
            EnvelopeMode::TopLevel,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var RestoreChatAccepted $result */
        return $result;
    }

    /**
     * Creates a chat inside a workspace (POST
     * /workspaces/{workspace_id}/chats).
     */
    public function createChat(string $workspaceId, ChatCreateRequest $request): Chat
    {
        $result = $this->requests->request(
            'POST',
            $this->requests->resolve('/workspaces/{workspace_id}/chats', ['workspace_id' => $workspaceId]),
            [],
            $request->toArray(),
            Chat::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::Bearer,
            RetrySafety::NeverRetry,
        );

        /** @var Chat $result */
        return $result;
    }

    /**
     * Lists the chats of a workspace (GET /workspaces/{workspace_id}/chats).
     *
     * @return ResourceCollection<Chat>
     */
    public function listChats(string $workspaceId): ResourceCollection
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve('/workspaces/{workspace_id}/chats', ['workspace_id' => $workspaceId]),
            [],
            null,
            Chat::class,
            EnvelopeMode::Collection,
        );

        /** @var ResourceCollection<Chat> $result */
        return $result;
    }

    /**
     * Gets a chat (GET /workspaces/{workspace_id}/chats/{chat_id}).
     */
    public function getChat(string $workspaceId, string $chatId): Chat
    {
        $result = $this->requests->request(
            'GET',
            $this->requests->resolve(
                '/workspaces/{workspace_id}/chats/{chat_id}',
                ['workspace_id' => $workspaceId, 'chat_id' => $chatId],
            ),
            [],
            null,
            Chat::class,
            EnvelopeMode::Envelope,
        );

        /** @var Chat $result */
        return $result;
    }
}
