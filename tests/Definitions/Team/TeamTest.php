<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Team;

use Digitalygo\Envai\Definitions\Agent\AgentMode;
use Digitalygo\Envai\Definitions\Team\Team;
use Digitalygo\Envai\Definitions\Team\TeamBuilder;
use Digitalygo\Envai\Definitions\Team\TeamFileCompiler;
use Digitalygo\Envai\Tests\Definitions\DefinitionsTestCase;

final class TeamTest extends DefinitionsTestCase
{
    public function test_builder_is_mutable_then_immutable_and_fluent(): void
    {
        $builder = Team::make('forecasting')
            ->name('Forecasting team')
            ->description('Researches and produces forecasts')
            ->orchestrator('orchestrator')
            ->member('researcher', role: 'research')
            ->member('forecaster');

        $team = $builder->build();

        $this->assertSame('forecasting', $team->localKey);
        $this->assertSame('Forecasting team', $team->name);
        $this->assertSame('Researches and produces forecasts', $team->description);
        $this->assertSame('orchestrator', $team->orchestratorKey);
        $this->assertCount(2, $team->members);
        $this->assertSame('researcher', $team->members[0]->localAgentKey);
        $this->assertSame('research', $team->members[0]->role);
        $this->assertSame('forecaster', $team->members[1]->localAgentKey);
        $this->assertNull($team->members[1]->role);
    }

    public function test_build_without_name_throws(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('missing its name');

        Team::make('team')->orchestrator('orchestrator')->build();
    }

    public function test_build_without_orchestrator_throws(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('missing its orchestrator');

        Team::make('team')->name('Team')->build();
    }

    public function test_team_builder_static_make_matches_the_facade(): void
    {
        $this->assertInstanceOf(TeamBuilder::class, TeamBuilder::make('key'));
        $this->assertSame('key', TeamBuilder::make('key')->localKey());
    }

    public function test_compiles_the_project_document_example(): void
    {
        $directory = $this->makeEnvaiLayout(teams: [
            'forecasting' => <<<'PHP'
            <?php

            use Digitalygo\Envai\Definitions\Team;

            return Team::make('forecasting')
                ->name('Forecasting team')
                ->description('Researches and produces forecasts')
                ->orchestrator('orchestrator')
                ->member('researcher', role: 'research')
                ->member('forecaster', role: 'forecasting');
            PHP,
        ]);

        $result = (new TeamFileCompiler)->compile($directory.DIRECTORY_SEPARATOR.'teams');

        $this->assertSame([], $result['violations']);
        $this->assertCount(1, $result['definitions']);

        $team = $result['definitions']['forecasting'];

        $this->assertSame('forecasting', $team->localKey);
        $this->assertSame('Forecasting team', $team->name);
        $this->assertSame('Researches and produces forecasts', $team->description);
        $this->assertSame('orchestrator', $team->orchestratorKey);
        $this->assertSame(['researcher', 'forecaster'], array_map(
            static fn ($member): string => $member->localAgentKey,
            $team->members,
        ));
    }

    public function test_member_order_is_preserved_in_the_remote_payload(): void
    {
        $team = Team::make('team')
            ->name('Team')
            ->orchestrator('orchestrator')
            ->member('zeta')
            ->member('alpha', role: 'first')
            ->build();

        $payload = $team->toRemotePayload(
            ['orchestrator' => 'agt_orchestrator', 'zeta' => 'agt_zeta', 'alpha' => 'agt_alpha'],
            ['orchestrator' => AgentMode::Primary, 'zeta' => AgentMode::Subagent, 'alpha' => AgentMode::Subagent],
        );

        $this->assertSame([
            'name' => 'Team',
            'orchestrator_id' => 'agt_orchestrator',
            'members' => [
                ['agent_definition_id' => 'agt_zeta', 'role' => null],
                ['agent_definition_id' => 'agt_alpha', 'role' => 'first'],
            ],
        ], $payload);
    }

    public function test_description_null_is_emitted_and_absent_description_omitted(): void
    {
        $withDescription = Team::make('a')->name('A')->description(null)->orchestrator('o')->build();
        $withoutDescription = Team::make('b')->name('B')->orchestrator('o')->build();
        $agentIds = ['o' => 'agt_o'];

        $this->assertArrayHasKey('description', $withDescription->toRemotePayload($agentIds, []));
        $this->assertNull($withDescription->toRemotePayload($agentIds, [])['description']);
        $this->assertArrayNotHasKey('description', $withoutDescription->toRemotePayload($agentIds, []));
    }

    public function test_remote_payload_with_unknown_agent_key_is_structural(): void
    {
        $team = Team::make('team')->name('Team')->orchestrator('ghost')->build();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No agent id available');

        $team->toRemotePayload([], []);
    }

    public function test_key_filename_mismatch_is_a_violation(): void
    {
        $directory = $this->makeEnvaiLayout(teams: [
            'forecasting' => $this->teamPhp('different_key', "
                ->name('Team')
                ->orchestrator('orchestrator')"),
        ]);

        $result = (new TeamFileCompiler)->compile($directory.DIRECTORY_SEPARATOR.'teams');

        $this->assertSame('team.key_filename_mismatch', $result['violations'][0]->code);
        $this->assertSame('forecasting', $result['violations'][0]->sourceKey);
        $this->assertArrayHasKey('different_key', $result['definitions']);
    }

    public function test_missing_name_or_orchestrator_is_a_build_failure_violation(): void
    {
        $directory = $this->makeEnvaiLayout(teams: [
            'no_name' => $this->teamPhp('no_name', "\n                ->orchestrator('orchestrator')"),
            'no_orchestrator' => $this->teamPhp('no_orchestrator', "\n                ->name('Team')"),
        ]);

        $result = (new TeamFileCompiler)->compile($directory.DIRECTORY_SEPARATOR.'teams');

        $this->assertCount(2, $result['violations']);
        $this->assertSame('team.build_failed', $result['violations'][0]->code);
        $this->assertSame('team.build_failed', $result['violations'][1]->code);
        $this->assertSame([], $result['definitions']);
    }

    public function test_file_not_returning_a_builder_is_a_violation(): void
    {
        $directory = $this->makeEnvaiLayout(teams: [
            'broken' => "<?php\n\nreturn 42;\n",
        ]);

        $result = (new TeamFileCompiler)->compile($directory.DIRECTORY_SEPARATOR.'teams');

        $this->assertSame('team.invalid_file', $result['violations'][0]->code);
        $this->assertSame([], $result['definitions']);
    }

    public function test_php_syntax_error_is_a_violation(): void
    {
        $directory = $this->makeEnvaiLayout(teams: [
            'syntax_error' => "<?php\n\nthis is not valid php\n",
        ]);

        $result = (new TeamFileCompiler)->compile($directory.DIRECTORY_SEPARATOR.'teams');

        $this->assertSame('team.invalid_file', $result['violations'][0]->code);
        $this->assertSame([], $result['definitions']);
    }

    public function test_missing_teams_directory_is_structural(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TeamFileCompiler)->compile('/nonexistent/teams');
    }

    public function test_multiple_team_files_compile_with_sorted_deterministic_order(): void
    {
        $directory = $this->makeEnvaiLayout(teams: [
            'zeta' => $this->teamPhp('zeta', "\n                ->name('Zeta')
                ->orchestrator('orchestrator')"),
            'alpha' => $this->teamPhp('alpha', "\n                ->name('Alpha')
                ->orchestrator('orchestrator')"),
        ]);

        $result = (new TeamFileCompiler)->compile($directory.DIRECTORY_SEPARATOR.'teams');

        $this->assertSame([], $result['violations']);
        $this->assertSame(['alpha', 'zeta'], array_keys($result['definitions']));
    }
}
