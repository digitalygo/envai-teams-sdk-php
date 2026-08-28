<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Support;

use Digitalygo\Envai\Streaming\Events\AgentToolRequestEvent;
use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Execution\ClientToolRegistry;
use Digitalygo\Envai\Tools\Execution\ClientToolRunner;
use Digitalygo\Envai\Tools\Execution\RunnerOptions;
use Digitalygo\Envai\Tools\Security\JwksResolver;
use Digitalygo\Envai\Tools\Security\ToolRequestVerifier;
use Digitalygo\Envai\Tools\State\InMemoryClientToolCallStore;
use Digitalygo\Envai\Tools\State\ToolCallRecord;

/**
 * Wired runner for a single test: real verifier over a fake JWKS transport,
 * in-memory store, recording sender and a class-keyed factory.
 *
 * @param  array<string, class-string<ClientTool>>  $registry  tools.php shaped registry entries.
 * @param  array<string, ClientTool>  $instances  Factory instances keyed by class-string.
 */
final class RunnerHarness
{
    /**
     * @var array{secret: non-empty-string, public: non-empty-string}
     */
    public array $keys;

    public string $kid;

    public FakeJwksTransport $transport;

    public JwksResolver $resolver;

    public ToolRequestVerifier $verifier;

    public InMemoryClientToolCallStore $store;

    public RecordingToolOutputSender $sender;

    public ClientToolRegistry $registry;

    public MapClientToolFactory $factory;

    public ClientToolRunner $runner;

    /**
     * @param  array<string, class-string<ClientTool>|array{class: class-string<ClientTool>, timeout_seconds?: int}>  $registry  tools.php shaped registry entries.
     * @param  array<string, ClientTool>  $instances
     */
    public function __construct(
        array $registry = [],
        array $instances = [],
        ?RunnerOptions $options = null,
        ?string $kid = null,
    ) {
        $this->keys = TestKeys::generate();
        $this->kid = $kid ?? TestKeys::kid();

        $this->transport = new FakeJwksTransport;
        $this->transport->document = TestKeys::jwkDocument($this->kid, $this->keys['public']);

        $this->resolver = new JwksResolver($this->transport, 'https://control.example/.well-known/jwks.json');
        $this->verifier = new ToolRequestVerifier($this->resolver);
        $this->store = new InMemoryClientToolCallStore;
        $this->sender = new RecordingToolOutputSender;
        $this->registry = ClientToolRegistry::fromArray($registry);
        $this->factory = new MapClientToolFactory($instances);

        $this->runner = new ClientToolRunner(
            $this->registry,
            $this->factory,
            $this->verifier,
            $this->store,
            $this->sender,
            options: $options ?? new RunnerOptions,
        );
    }

    /**
     * Builds a properly signed event for the harness key and kid.
     *
     * @param  array<string, mixed>  $args
     */
    public function event(string $callId, string $toolName, array $args = [], ?string $turnId = 'cmpl_test_1'): AgentToolRequestEvent
    {
        return ToolRequestFactory::signed($callId, $toolName, $args, $this->keys['secret'], $this->kid, $turnId);
    }

    /**
     * Returns the most recent recorded submission for the given call id.
     *
     * @return array{chatId: string, callId: string, output: string, isError: bool}
     */
    public function submission(string $callId): array
    {
        $submission = $this->sender->byCallId($callId);

        if ($submission === null) {
            throw new \LogicException(sprintf('No output submission recorded for call "%s".', $callId));
        }

        return $submission;
    }

    public function record(string $callId): ?ToolCallRecord
    {
        foreach ($this->store->records() as $record) {
            if ($record->identity->callId === $callId) {
                return $record;
            }
        }

        return null;
    }
}
