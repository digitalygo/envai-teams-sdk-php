<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client\Internal;

use Digitalygo\Envai\Client\AgentsService;
use Digitalygo\Envai\Client\ApiKeysService;
use Digitalygo\Envai\Client\CapabilitiesService;
use Digitalygo\Envai\Client\ChatsService;
use Digitalygo\Envai\Client\CompletionsService;
use Digitalygo\Envai\Client\ImagesService;
use Digitalygo\Envai\Client\ModelsService;
use Digitalygo\Envai\Client\TeamsService;
use Digitalygo\Envai\Client\ToolsService;
use Digitalygo\Envai\Client\WorkspaceFilesService;
use Digitalygo\Envai\Client\WorkspacesService;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Contracts\EventStreamTransport;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;

/**
 * Lazily creates and caches the service instances of one EnvaiClient.
 *
 * EnvaiClient is readonly, so the mutable per-service caches live here: the
 * first call to a getter constructs the service, every later call returns
 * the same instance.
 */
final class ServiceContainer
{
    private ?WorkspacesService $workspaces = null;

    private ?ToolsService $tools = null;

    private ?WorkspaceFilesService $workspaceFiles = null;

    private ?ChatsService $chats = null;

    private ?CompletionsService $completions = null;

    private ?AgentsService $agents = null;

    private ?TeamsService $teams = null;

    private ?ModelsService $models = null;

    private ?ApiKeysService $apiKeys = null;

    private ?ImagesService $images = null;

    private ?CapabilitiesService $capabilities = null;

    public function __construct(
        private RequestExecutor $executor,
        private UriResolver $uris,
        private EventStreamTransport $streams,
        private SensitiveString $apiKey,
        private int $connectTimeoutSeconds = 10,
        private int $requestTimeoutSeconds = 60,
        private ?int $streamTimeoutSeconds = null,
    ) {}

    public function workspaces(): WorkspacesService
    {
        return $this->workspaces ??= new WorkspacesService(
            $this->executor,
            $this->uris,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
        );
    }

    public function tools(): ToolsService
    {
        return $this->tools ??= new ToolsService(
            $this->executor,
            $this->uris,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
        );
    }

    public function workspaceFiles(): WorkspaceFilesService
    {
        return $this->workspaceFiles ??= new WorkspaceFilesService(
            $this->executor,
            $this->uris,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
        );
    }

    public function chats(): ChatsService
    {
        return $this->chats ??= new ChatsService(
            $this->executor,
            $this->uris,
            $this->streams,
            $this->apiKey,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
            $this->streamTimeoutSeconds,
        );
    }

    public function completions(): CompletionsService
    {
        return $this->completions ??= new CompletionsService(
            $this->executor,
            $this->uris,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
        );
    }

    public function agents(): AgentsService
    {
        return $this->agents ??= new AgentsService(
            $this->executor,
            $this->uris,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
        );
    }

    public function teams(): TeamsService
    {
        return $this->teams ??= new TeamsService(
            $this->executor,
            $this->uris,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
        );
    }

    public function models(): ModelsService
    {
        return $this->models ??= new ModelsService(
            $this->executor,
            $this->uris,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
        );
    }

    public function apiKeys(): ApiKeysService
    {
        return $this->apiKeys ??= new ApiKeysService(
            $this->executor,
            $this->uris,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
        );
    }

    public function images(): ImagesService
    {
        return $this->images ??= new ImagesService(
            $this->executor,
            $this->uris,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
        );
    }

    public function capabilities(): CapabilitiesService
    {
        return $this->capabilities ??= new CapabilitiesService(
            $this->executor,
            $this->uris,
            $this->connectTimeoutSeconds,
            $this->requestTimeoutSeconds,
        );
    }
}
