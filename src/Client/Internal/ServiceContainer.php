<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client\Internal;

use Digitalygo\Envai\Client\AgentsService;
use Digitalygo\Envai\Client\ApiKeysService;
use Digitalygo\Envai\Client\CapabilitiesService;
use Digitalygo\Envai\Client\ChatRuntime;
use Digitalygo\Envai\Client\ChatsService;
use Digitalygo\Envai\Client\ChatsToolOutputSender;
use Digitalygo\Envai\Client\ClientToolRuntime;
use Digitalygo\Envai\Client\CompletionsService;
use Digitalygo\Envai\Client\ImagesService;
use Digitalygo\Envai\Client\ModelsService;
use Digitalygo\Envai\Client\TeamsService;
use Digitalygo\Envai\Client\ToolsService;
use Digitalygo\Envai\Client\WikiOperationsService;
use Digitalygo\Envai\Client\WorkspaceFilesService;
use Digitalygo\Envai\Client\WorkspaceScope;
use Digitalygo\Envai\Client\WorkspacesService;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Contracts\EventStreamTransport;
use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Definitions\Compiler\DefinitionCompiler;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Sync\Client\ClientRemoteReader;
use Digitalygo\Envai\Sync\Client\ClientRemoteWriter;
use Digitalygo\Envai\Sync\Client\StateStoreFactory;
use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\State\SyncStateStore;
use Digitalygo\Envai\Tools\Contracts\ReflectionClientToolFactory;
use Digitalygo\Envai\Tools\Execution\ClientToolRegistry;
use Digitalygo\Envai\Tools\Security\JwksResolver;
use Digitalygo\Envai\Tools\Security\ToolRequestVerifier;
use Digitalygo\Envai\Tools\State\InMemoryClientToolCallStore;

/**
 * Lazily creates and caches the service instances of one EnvaiClient.
 *
 * EnvaiClient is readonly, so the mutable per-service caches live here: the
 * first call to a getter constructs the service, every later call returns
 * the same instance.
 *
 * The high level facades are cached per identity key: definitions() per
 * connection + definitions path, workspace() per workspace id,
 * chat() per workspace + chat id and clientTools() per registry instance
 * (a single default when no registry is given). The definitions facade
 * wires the sync core over the client services (ClientRemoteReader/
 * Writer, StateStoreFactory, DefinitionCompiler) and feeds the model
 * catalog from ClientRemoteReader::listModelIds() into the compiler, so
 * plan()/apply() fail closed when the catalog cannot be read.
 */
final class ServiceContainer
{
    private ?WikiOperationsService $wiki = null;

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

    /**
     * @var array<string, DefinitionsService>
     */
    private array $definitions = [];

    /**
     * @var array<string, WorkspaceScope>
     */
    private array $workspaceScopes = [];

    /**
     * @var array<string, ChatRuntime>
     */
    private array $chatRuntimes = [];

    /**
     * @var array<string, ClientToolRuntime>
     */
    private array $clientToolRuntimes = [];

    /**
     * @param  \Closure(string, array<string, mixed>): SyncStateStore|null  $stateStoreResolver  Resolves the sync state store for a driver name and its options; the optional Laravel integration uses it for the `laravel` driver, the default resolution goes through StateStoreFactory.
     */
    public function __construct(
        private RequestExecutor $executor,
        private UriResolver $uris,
        private EventStreamTransport $streams,
        private SensitiveString $apiKey,
        private int $connectTimeoutSeconds = 10,
        private int $requestTimeoutSeconds = 60,
        private ?int $streamTimeoutSeconds = null,
        private readonly EnvaiConfig $config = new EnvaiConfig(
            baseUrl: 'https://api.invalid',
            apiKey: new SensitiveString('unused'),
        ),
        private readonly ?\Closure $stateStoreResolver = null,
    ) {}

    public function wiki(): WikiOperationsService
    {
        return $this->wiki ??= new WikiOperationsService($this->executor, $this->uris, $this->connectTimeoutSeconds, $this->requestTimeoutSeconds);
    }

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

    /**
     * The definitions facade for one connection and definitions path,
     * cached per identity. The connection defaults to the configured
     * connection name; the path defaults to the configured definitions
     * path (or `envai`). The state store driver defaults to `file` (or
     * the configured driver) with the configured file path.
     *
     * @param  \Closure(string, array<string, mixed>): SyncStateStore|null  $stateStoreResolver  Resolver override; when omitted the resolver wired at construction (if any) or StateStoreFactory is used. The cache is keyed by connection + path only: the resolver must be stable per client.
     */
    public function definitions(?string $definitionsPath = null, ?string $connection = null, ?\Closure $stateStoreResolver = null): DefinitionsService
    {
        $connection ??= $this->config->connectionName;
        $path = $definitionsPath ?? $this->config->definitionsPath ?? 'envai';

        $key = $connection."\0".$path;

        if (isset($this->definitions[$key])) {
            return $this->definitions[$key];
        }

        $reader = new ClientRemoteReader($this->tools(), $this->agents(), $this->teams(), $this->models());
        $writer = new ClientRemoteWriter($this->tools(), $this->agents(), $this->teams());

        $store = $this->stateStore($stateStoreResolver);

        return $this->definitions[$key] = new DefinitionsService(
            compiler: new DefinitionCompiler,
            store: $store,
            remoteReader: $reader,
            remoteWriter: $writer,
            connection: $connection,
            definitionsPath: $path,
            factory: new ReflectionClientToolFactory,
            catalogProvider: fn (): ?array => $reader->listModelIds(),
        );
    }

    /**
     * Resolves the sync state store for the configured driver: through
     * the resolver closure when one is wired (the optional Laravel
     * integration resolves its `laravel` driver there), otherwise
     * through StateStoreFactory.
     *
     * @param  \Closure(string, array<string, mixed>): SyncStateStore|null  $stateStoreResolver
     */
    private function stateStore(?\Closure $stateStoreResolver): SyncStateStore
    {
        $driver = $this->config->stateDriver ?? 'file';
        $options = ['path' => $this->config->stateFilePath ?? '.envai'];

        $resolver = $stateStoreResolver ?? $this->stateStoreResolver;

        if ($resolver !== null) {
            return $resolver($driver, $options);
        }

        return (new StateStoreFactory)->create($driver, $options);
    }

    /**
     * The workspace facade for one workspace id, cached per id.
     */
    public function workspace(string $workspaceId): WorkspaceScope
    {
        return $this->workspaceScopes[$workspaceId] ??= new WorkspaceScope(
            $this->chats(),
            $workspaceId,
        );
    }

    /**
     * The chat facade for one chat, cached per workspace + chat id.
     */
    public function chat(string $workspaceId, string $chatId): ChatRuntime
    {
        $key = $workspaceId."\0".$chatId;

        return $this->chatRuntimes[$key] ??= new ChatRuntime(
            $workspaceId,
            $chatId,
            $this->chats(),
        );
    }

    /**
     * The client tool runtime facade, cached per registry instance (or a
     * single default when no registry is given). Without an explicit
     * registry the tool definitions are compiled from the local
     * definitions manifest.
     */
    public function clientTools(?ClientToolRegistry $registry = null): ClientToolRuntime
    {
        $key = $registry === null ? 'default' : 'registry:'.spl_object_id($registry);

        if (isset($this->clientToolRuntimes[$key])) {
            return $this->clientToolRuntimes[$key];
        }

        $registry ??= $this->registryFromDefinitions();

        if (! $this->streams instanceof HttpTransport) {
            throw new \LogicException('The injected transport must implement HttpTransport to resolve the JWKS document for client tool verification.');
        }

        $verifier = new ToolRequestVerifier(new JwksResolver($this->streams, $this->uris->jwksUrl()));

        $this->clientToolRuntimes[$key] = new ClientToolRuntime(
            registry: $registry,
            verifier: $verifier,
            sender: new ChatsToolOutputSender($this->chats()),
            callStore: new InMemoryClientToolCallStore,
        );

        return $this->clientToolRuntimes[$key];
    }

    /**
     * Builds the tool registry from the compiled local definitions: the
     * registry key is the remote tool name and the handler class and
     * timeout come from the tool definition.
     */
    private function registryFromDefinitions(): ClientToolRegistry
    {
        $registry = [];

        foreach ($this->definitions()->manifest()->tools as $localKey => $tool) {
            $registry[$localKey] = [
                'class' => $tool->handlerClass,
                'timeout_seconds' => $tool->timeoutSeconds,
            ];
        }

        return ClientToolRegistry::fromArray($registry);
    }
}
