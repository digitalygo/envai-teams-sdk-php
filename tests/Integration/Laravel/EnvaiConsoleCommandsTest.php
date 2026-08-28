<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Definitions\Compiler\DefinitionCompiler;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiPlanCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiStateCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiStatusCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiSyncCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiValidateCommand;
use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\FakeEchoTool;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Artisan alias command tests: every command receives an injected
 * DefinitionsService wired over the in-memory store and the scripted
 * remote fakes, plus the definitions path so the core command's
 * directory check sees the layout. The delegation to the core commands
 * is in-process (never a shell out), and the exit codes and text/JSON
 * output mirror the standalone CLI.
 */
final class EnvaiConsoleCommandsTest extends IntegrationTestCase
{
    private const C = 'production';

    public function test_validate_exits_zero_on_a_valid_layout(): void
    {
        $tester = $this->tester(EnvaiValidateCommand::class);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Valid: yes', $tester->getDisplay());
        $this->assertStringContainsString('Connection: '.self::C, $tester->getDisplay());
    }

    public function test_validate_emits_json(): void
    {
        $tester = $this->tester(EnvaiValidateCommand::class);

        $tester->execute(['--json' => true]);

        $this->assertSame(0, $tester->getStatusCode());

        $document = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $document['validate_format']);
        $this->assertTrue($document['valid']);
    }

    public function test_plan_exits_zero_and_renders_the_plan_text(): void
    {
        $tester = $this->tester(EnvaiPlanCommand::class);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());

        $display = $tester->getDisplay();

        $this->assertStringContainsString('Connection: '.self::C, $display);
        $this->assertStringContainsString('Manifest:   sha256:', $display);
        $this->assertStringContainsString('+ query_db', $display);
    }

    public function test_plan_emits_json(): void
    {
        $tester = $this->tester(EnvaiPlanCommand::class);

        $tester->execute(['--json' => true]);

        $this->assertSame(0, $tester->getStatusCode());

        $document = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $document['plan_format']);
    }

    public function test_sync_dry_run_plans_without_mutating(): void
    {
        $tester = $this->tester(EnvaiSyncCommand::class);

        $tester->execute(['--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Manifest ready: no', $tester->getDisplay());
    }

    public function test_sync_applies_and_marks_the_manifest_ready(): void
    {
        [$tester, $store] = $this->tester_and_store(EnvaiSyncCommand::class);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Manifest ready: yes', $tester->getDisplay());

        $ready = $store->readyManifest(self::C);

        $this->assertNotNull($ready);
        $this->assertSame($this->service()->manifest()->hash, $ready->manifestHash);
    }

    public function test_sync_emits_json(): void
    {
        $tester = $this->tester(EnvaiSyncCommand::class);

        $tester->execute(['--json' => true]);

        $this->assertSame(0, $tester->getStatusCode());

        $document = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $document['report_format']);
        $this->assertTrue($document['manifest_ready']);
    }

    public function test_sync_honors_the_only_scope(): void
    {
        $tester = $this->tester(EnvaiSyncCommand::class);

        $tester->execute(['--only' => ['tools']]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Manifest ready: no', $tester->getDisplay());
    }

    public function test_status_reports_not_synchronized_before_sync(): void
    {
        $tester = $this->tester(EnvaiStatusCommand::class);

        $tester->execute([]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Synchronized: no', $tester->getDisplay());
    }

    public function test_status_reports_synchronized_after_sync(): void
    {
        [$sync, $store] = $this->tester_and_store(EnvaiSyncCommand::class);
        [$status] = $this->tester_and_store(EnvaiStatusCommand::class, $store);

        $sync->execute([]);
        $status->execute([]);

        $this->assertSame(0, $status->getStatusCode());
        $this->assertStringContainsString('Synchronized: yes', $status->getDisplay());
    }

    public function test_state_dumps_the_redacted_store(): void
    {
        $tester = $this->tester(EnvaiStateCommand::class);

        $tester->execute(['--json' => true]);

        $this->assertSame(0, $tester->getStatusCode());

        $document = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $document['state_format']);
        $this->assertNull($document['manifest']);
        $this->assertSame([], $document['resources']);
    }

    public function test_run_without_a_service_binding_fails_loudly(): void
    {
        $command = new EnvaiSyncCommand;

        // Illuminate 13's Command::run() needs the application container
        // even before execute(): without a DefinitionsService binding the
        // resolution fails loudly (Symfony 8 propagates it instead of
        // converting it to an exit code).
        $app = new TestConsoleContainer;
        $app->instance('config', new ConfigRepository);
        $command->setLaravel($app);

        $this->expectException(BindingResolutionException::class);

        $command->run(new ArrayInput([]), new NullOutput);
    }

    /**
     * @param  class-string<Command>  $commandClass
     */
    private function tester(string $commandClass): CommandTester
    {
        [$tester] = $this->tester_and_store($commandClass);

        return $tester;
    }

    /**
     * @param  class-string<Command>  $commandClass
     * @return array{0: CommandTester, 1: InMemorySyncStateStore}
     */
    private function tester_and_store(string $commandClass, ?InMemorySyncStateStore $store = null): array
    {
        $store ??= new InMemorySyncStateStore;
        $reader = new FakeRemoteReader;
        $writer = new FakeRemoteWriter;
        $path = $this->layout();

        $service = new DefinitionsService(
            compiler: new DefinitionCompiler,
            store: $store,
            remoteReader: $reader,
            remoteWriter: $writer,
            connection: self::C,
            definitionsPath: $path,
        );

        $command = new $commandClass($service, $path);

        // Illuminate 13's Command::run() resolves its OutputStyle and
        // component factory from the application container and probes
        // runningUnitTests() while configuring prompts.
        $app = new TestConsoleContainer;
        $app->instance('config', new ConfigRepository);
        $command->setLaravel($app);

        return [new CommandTester($command), $store];
    }

    private function service(): DefinitionsService
    {
        return new DefinitionsService(
            compiler: new DefinitionCompiler,
            store: new InMemorySyncStateStore,
            remoteReader: new FakeRemoteReader,
            remoteWriter: new FakeRemoteWriter,
            connection: self::C,
            definitionsPath: $this->layout(),
        );
    }

    private function layout(): string
    {
        return $this->makeEnvaiLayout(
            agents: [
                'orchestrator' => $this->agentMarkdown('You orchestrate.', "format: 1\nname: orchestrator\nmode: primary\n"),
            ],
            teams: [
                'forecasting' => $this->teamPhp('forecasting', "\n    ->name('Forecasting team')\n    ->orchestrator('orchestrator')"),
            ],
            tools: $this->toolPhp('query_db', FakeEchoTool::class),
        );
    }
}

/**
 * Container used by the command tests: Illuminate 13's command runner
 * probes runningUnitTests() while configuring prompts, a method that
 * only Illuminate\Foundation\Application exposes. The subclass covers it
 * without pulling the whole foundation package into the dev workflow.
 *
 * Declared conditionally: the class declaration would otherwise fatal
 * at load time in environments without the Illuminate packages, before
 * the setUp() skip guard can run.
 */
if (class_exists(Container::class)) {
    final class TestConsoleContainer extends Container
    {
        public function runningUnitTests(): bool
        {
            return true;
        }
    }
}
