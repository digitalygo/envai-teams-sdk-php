<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\ServiceContainer;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Contracts\EventStreamTransport;
use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\Curl\CurlTransport;
use Psr\Log\LoggerInterface;

/**
 * Entry point of the Envai HTTP client.
 *
 * Builds the UriResolver, the RequestExecutor (injecting the api key and the
 * retry policy from the configuration) and lazily creates the service
 * instances through the internal ServiceContainer. The injected transport
 * must also implement EventStreamTransport because chat streaming (SSE)
 * shares the same transport: CurlTransport does.
 */
final readonly class EnvaiClient
{
    private EnvaiConfig $config;

    private HttpTransport $transport;

    private UriResolver $uris;

    private RequestExecutor $executor;

    private ServiceContainer $services;

    public function __construct(
        EnvaiConfig $config,
        ?HttpTransport $transport = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->config = $config;
        $this->transport = $transport ?? new CurlTransport;

        if (! $this->transport instanceof EventStreamTransport) {
            throw new \InvalidArgumentException('The injected HttpTransport must also implement EventStreamTransport to support chat event streaming; use CurlTransport or a transport implementing both interfaces.');
        }

        $this->uris = new UriResolver($config->baseUrl, $config->jwksUrl);
        $this->executor = new RequestExecutor(
            $this->transport,
            $this->uris,
            $config->retryPolicy,
            $logger,
            $config->apiKey,
        );

        $this->services = new ServiceContainer(
            executor: $this->executor,
            uris: $this->uris,
            streams: $this->transport,
            apiKey: $config->apiKey,
            connectTimeoutSeconds: $config->connectTimeoutSeconds,
            requestTimeoutSeconds: $config->requestTimeoutSeconds,
            streamTimeoutSeconds: $config->streamTimeoutSeconds,
        );
    }

    /**
     * The transport in use; exposed for advanced use cases (health probing,
     * custom retry instrumentation).
     */
    public function transport(): HttpTransport
    {
        return $this->transport;
    }

    /**
     * The configuration this client was built from.
     */
    public function config(): EnvaiConfig
    {
        return $this->config;
    }

    public function workspaces(): WorkspacesService
    {
        return $this->services->workspaces();
    }

    public function tools(): ToolsService
    {
        return $this->services->tools();
    }

    public function workspaceFiles(): WorkspaceFilesService
    {
        return $this->services->workspaceFiles();
    }

    public function chats(): ChatsService
    {
        return $this->services->chats();
    }

    public function completions(): CompletionsService
    {
        return $this->services->completions();
    }

    public function agents(): AgentsService
    {
        return $this->services->agents();
    }

    public function teams(): TeamsService
    {
        return $this->services->teams();
    }

    public function models(): ModelsService
    {
        return $this->services->models();
    }

    public function apiKeys(): ApiKeysService
    {
        return $this->services->apiKeys();
    }

    public function images(): ImagesService
    {
        return $this->services->images();
    }

    public function capabilities(): CapabilitiesService
    {
        return $this->services->capabilities();
    }
}
