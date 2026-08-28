<?php

declare(strict_types=1);

/*
 * Illustrative example: a native client tool handler plus the runtime
 * wiring that verifies, executes and delivers tool calls.
 *
 * Run with:
 *
 *     ENVAI_API_KEY=... php examples/client-tool.php
 *
 * The class definitions mirror what examples/definitions/tools.php
 * references. In a real application the handler classes live in your
 * codebase and the registry in envai/tools.php.
 */

namespace Examples\Tools;

use Digitalygo\Envai\Client\ChatsToolOutputSender;
use Digitalygo\Envai\Client\ClientToolRuntime;
use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ToolInvocation;
use Digitalygo\Envai\Tools\Contracts\ToolResult;
use Digitalygo\Envai\Tools\Execution\ClientToolRegistry;
use Digitalygo\Envai\Tools\Security\JwksResolver;
use Digitalygo\Envai\Tools\Security\ToolRequestVerifier;
use Digitalygo\Envai\Tools\State\FileClientToolCallStore;

final class QueryForecastingDatabase implements ClientTool
{
    public function description(): string
    {
        return 'Runs a read-only query against the forecasting database.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'SQL query to run'],
                'limit' => ['type' => 'integer', 'default' => 10],
            ],
            'required' => ['query'],
        ];
    }

    public function handle(ToolInvocation $invocation): string|ToolResult
    {
        // Replace with a real, read-only database call. The workspace is
        // not part of the tool identity; it may be carried in the
        // arguments when the platform provides it.
        $rows = $this->runReadOnlyQuery($invocation->arguments['query']);

        return ToolResult::json(['rows' => $rows]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runReadOnlyQuery(string $sql): array
    {
        return [];
    }
}

final class RetrieveMarketPrices implements ClientTool
{
    public function description(): string
    {
        return 'Fetches current market prices for a ticker.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'ticker' => ['type' => 'string'],
            ],
            'required' => ['ticker'],
        ];
    }

    public function handle(ToolInvocation $invocation): string|ToolResult
    {
        return ToolResult::json([
            'ticker' => $invocation->arguments['ticker'],
            'price' => null, // replace with a real price fetch
        ]);
    }
}

$entry = realpath($_SERVER['argv'][0] ?? '');

if (PHP_SAPI === 'cli' && $entry === __FILE__) {
    use_runner_wiring();
}

function use_runner_wiring(): void
{
    $envai = require __DIR__.'/config.php';

    // The registry accepts the same shape as envai/tools.php: handler
    // class or [class, timeout_seconds].
    $registry = ClientToolRegistry::fromArray(
        require __DIR__.'/definitions/tools.php',
    );

    // $envai->clientTools($registry) is the quick path but always wires
    // an InMemoryClientToolCallStore, which does not survive restarts
    // and is not shared between processes. For production, build the
    // runtime directly with a durable call store so a crashed worker
    // never re-executes a call that was already resolved or in flight.
    $verifier = new ToolRequestVerifier(
        new JwksResolver(
            $envai->transport(),
            $envai->config()->effectiveJwksUrl(),
        ),
    );

    $runtime = new ClientToolRuntime(
        registry: $registry,
        verifier: $verifier,
        sender: new ChatsToolOutputSender($envai->chats()),
        callStore: new FileClientToolCallStore(
            __DIR__.'/var/tool-calls.json',
        ),
    );

    // Feed the event stream of a chat; every agent.tool.request event
    // is verified (EdDSA over call_id + tool_name + canonical_json(args)),
    // executed at most once per call_id and answered through the
    // tool-output endpoint.
    $events = $envai->chat('wsp_xxxxxxxx', 'cht_xxxxxxxx')->events();
    $results = $runtime->handleEvents($events, 'cht_xxxxxxxx');

    foreach ($results as $result) {
        printf(
            "call=%s outcome=%s submitted=%s\n",
            $result->callId,
            $result->outcome->value,
            $result->outputSubmitted ? 'yes' : 'no',
        );
    }
}
