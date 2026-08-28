<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Tools\Contracts\ClientToolFactory;
use Digitalygo\Envai\Tools\Contracts\ReflectionClientToolFactory;
use Digitalygo\Envai\Tools\Execution\ClientToolRegistry;
use Digitalygo\Envai\Tools\Execution\ClientToolRunner;
use Digitalygo\Envai\Tools\Execution\RunnerOptions;
use Digitalygo\Envai\Tools\Execution\ToolOutputSender;
use Digitalygo\Envai\Tools\Execution\ToolRunResult;
use Digitalygo\Envai\Tools\Security\ToolRequestVerifier;
use Digitalygo\Envai\Tools\State\ClientToolCallStore;
use Digitalygo\Envai\Tools\State\InMemoryClientToolCallStore;
use Psr\Log\LoggerInterface;

/**
 * High level facade over the client tool runtime: verifies signed
 * `agent.tool.request` events, executes the registered handlers and
 * delivers the outputs back to the chat.
 *
 * The default wiring uses an InMemoryClientToolCallStore, which is safe
 * for tests and single-process development but does not survive a
 * restart and is not shared between processes. Pass a durable store
 * (for example FileClientToolCallStore) for production so a crashed
 * worker never re-executes a call that was already resolved or is still
 * in flight.
 */
final class ClientToolRuntime
{
    private ?ClientToolRunner $runner = null;

    public function __construct(
        private readonly ClientToolRegistry $registry,
        private readonly ToolRequestVerifier $verifier,
        private readonly ToolOutputSender $sender,
        private readonly ClientToolCallStore $callStore = new InMemoryClientToolCallStore,
        private readonly ?ClientToolFactory $factory = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly RunnerOptions $options = new RunnerOptions,
    ) {}

    /**
     * The wired ClientToolRunner (cached): verification, registry lookup,
     * schema validation, at-most-once guard and output delivery.
     */
    public function runner(): ClientToolRunner
    {
        return $this->runner ??= new ClientToolRunner(
            $this->registry,
            $this->factory ?? new ReflectionClientToolFactory,
            $this->verifier,
            $this->callStore,
            $this->sender,
            $this->logger,
            $this->options,
        );
    }

    /**
     * Passthrough over the runner: filters AgentToolRequestEvent instances
     * from an event iterable and runs them in order against the given
     * chat id.
     *
     * @param  iterable<mixed>  $events
     * @return list<ToolRunResult>
     */
    public function handleEvents(iterable $events, string $chatId): array
    {
        return $this->runner()->handleEvents($events, $chatId);
    }
}
