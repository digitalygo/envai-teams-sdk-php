<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Console;

use Digitalygo\Envai\Definitions\Compiler\DefinitionCompiler;
use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\FakeEchoTool;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;
use Digitalygo\Envai\Tests\Sync\SyncTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Shared harness for the CLI command tests: every command receives a
 * factory closure that builds an in-memory wired DefinitionsService over
 * the FakeRemoteReader/Writer and the shared InMemorySyncStateStore, so
 * the command line options (connection, definitions path) drive a real
 * service while the remote side stays scripted.
 */
abstract class ConsoleTestCase extends SyncTestCase
{
    protected const CONNECTION = 'production';

    /**
     * @param  class-string<Command>  $commandClass
     */
    protected function command(
        string $commandClass,
        FakeRemoteReader $reader,
        FakeRemoteWriter $writer,
        InMemorySyncStateStore $store,
    ): CommandTester {
        $factory = function (string $connection, string $definitionsPath, string $stateDriver, string $stateFilePath) use ($reader, $writer, $store): DefinitionsService {
            return new DefinitionsService(
                new DefinitionCompiler,
                $store,
                $reader,
                $writer,
                connection: $connection,
                definitionsPath: $definitionsPath,
            );
        };

        return new CommandTester(new $commandClass($factory));
    }

    /**
     * @return array{path: string, tester: CommandTester}
     */
    protected function validated(
        string $commandClass,
        FakeRemoteReader $reader,
        FakeRemoteWriter $writer,
        InMemorySyncStateStore $store,
        array $extra = [],
    ): array {
        $tester = $this->command($commandClass, $reader, $writer, $store);
        $path = $this->layout();

        $tester->execute(array_merge([
            '--connection' => self::CONNECTION,
            '--definitions-path' => $path,
            '--state-driver' => 'inmemory',
        ], $extra));

        return ['path' => $path, 'tester' => $tester];
    }

    /**
     * The standard layout: one tool, two agents and one team.
     */
    protected function layout(): string
    {
        return $this->makeEnvaiLayout(
            agents: [
                'orchestrator' => $this->agentMarkdown('You orchestrate.', "format: 1\nname: orchestrator\nmode: primary\n"),
                'researcher' => $this->agentMarkdown('You research.', "format: 1\nname: researcher\nmode: subagent\n"),
            ],
            teams: [
                'forecasting' => $this->teamPhp('forecasting', "\n    ->name('Forecasting team')\n    ->orchestrator('orchestrator')\n    ->member('researcher')"),
            ],
            tools: $this->toolPhp('query_db', FakeEchoTool::class),
        );
    }

    /**
     * The applied payload shape of the compiled `query_db` tool: the
     * FakeEchoTool schema and the default registry timeout.
     *
     * @return array<string, mixed>
     */
    protected function desiredToolPayload(string $name, ?string $description = null): array
    {
        return [
            'name' => $name,
            'kind' => 'client',
            'description' => $description ?? 'Echoes the given text back.',
            'parameters' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
            'timeout_seconds' => 120,
        ];
    }

    /**
     * The remote wire shape of the compiled `query_db` tool: the applied
     * payload plus the server bookkeeping keys.
     *
     * @return array<string, mixed>
     */
    protected function remoteDesiredTool(string $name, ?string $description = null): array
    {
        return array_merge($this->desiredToolPayload($name, $description), [
            'id' => 'twl_'.$name,
            'user_id' => 'usr_test',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ]);
    }
}
