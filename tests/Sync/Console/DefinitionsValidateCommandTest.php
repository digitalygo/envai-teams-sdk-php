<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Console;

use Digitalygo\Envai\Sync\Console\DefinitionsValidateCommand;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;

final class DefinitionsValidateCommandTest extends ConsoleTestCase
{
    public function test_valid_definitions_exit_zero_with_text_report(): void
    {
        $harness = $this->validated(
            DefinitionsValidateCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            new InMemorySyncStateStore,
        );

        $output = $harness['tester']->getDisplay();

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Connection: production', $output);
        $this->assertStringContainsString('Definitions: '.$harness['path'], $output);
        $this->assertStringContainsString('Valid: yes', $output);
        $this->assertMatchesRegularExpression('/Manifest:   sha256:[0-9a-f]{64}/', $output);
    }

    public function test_invalid_definitions_exit_one_with_violation_lines(): void
    {
        $reader = new FakeRemoteReader;
        $store = new InMemorySyncStateStore;
        $tester = $this->command(DefinitionsValidateCommand::class, $reader, new FakeRemoteWriter, $store);

        $path = $this->makeEnvaiLayout(
            agents: [
                'broken' => $this->agentMarkdown('', "format: 1\nname: broken\nmode: subagent\n"),
            ],
        );

        $tester->execute([
            '--connection' => self::CONNECTION,
            '--definitions-path' => $path,
            '--state-driver' => 'inmemory',
        ]);

        $output = $tester->getDisplay();

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Valid: no', $output);
        $this->assertStringContainsString('Violations: 1', $output);
        $this->assertStringContainsString('agent.empty_body', $output);
        $this->assertStringContainsString('! broken (body) agent.empty_body:', $output);
    }

    public function test_json_output_shape_for_valid_definitions(): void
    {
        $harness = $this->validated(
            DefinitionsValidateCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            new InMemorySyncStateStore,
            ['--json' => true],
        );

        $document = json_decode($harness['tester']->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $document['validate_format']);
        $this->assertSame(self::CONNECTION, $document['connection']);
        $this->assertSame($harness['path'], $document['definitions_path']);
        $this->assertTrue($document['valid']);
        $this->assertSame([], $document['violations']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $document['manifest_hash']);
    }

    public function test_json_output_lists_violations_with_codes(): void
    {
        $reader = new FakeRemoteReader;
        $store = new InMemorySyncStateStore;
        $tester = $this->command(DefinitionsValidateCommand::class, $reader, new FakeRemoteWriter, $store);

        $path = $this->makeEnvaiLayout(
            agents: [
                'sub' => $this->agentMarkdown('Body.', "format: 1\nname: sub\nmode: subagent\n"),
            ],
            teams: [
                'missing' => $this->teamPhp('missing', "\n    ->name('Missing')\n    ->orchestrator('unknown')"),
                'subagent' => $this->teamPhp('subagent', "\n    ->name('Subagent')\n    ->orchestrator('sub')"),
            ],
        );

        $tester->execute([
            '--connection' => self::CONNECTION,
            '--definitions-path' => $path,
            '--state-driver' => 'inmemory',
            '--json' => true,
        ]);

        $document = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertFalse($document['valid']);
        $this->assertNotSame([], $document['violations']);

        $codes = array_column($document['violations'], 'code');

        $this->assertContains('team.orchestrator_missing', $codes);
        $this->assertContains('team.orchestrator_not_primary', $codes);
    }

    public function test_missing_definitions_directory_exits_two(): void
    {
        $tester = $this->command(
            DefinitionsValidateCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            new InMemorySyncStateStore,
        );

        $tester->execute([
            '--connection' => self::CONNECTION,
            '--definitions-path' => '/tmp/definitely-not-present-'.bin2hex(random_bytes(4)),
            '--state-driver' => 'inmemory',
        ]);

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function test_validate_is_pure_local_no_remote_reads(): void
    {
        $reader = new FakeRemoteReader;
        $harness = $this->validated(DefinitionsValidateCommand::class, $reader, new FakeRemoteWriter, new InMemorySyncStateStore);

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertSame(0, $reader->toolsReads);
        $this->assertSame(0, $reader->agentsReads);
        $this->assertSame(0, $reader->teamsReads);
    }
}
