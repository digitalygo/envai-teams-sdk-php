<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\Common\OptionalField;
use Digitalygo\Envai\Models\Team\Team;
use Digitalygo\Envai\Models\Team\TeamCreateRequest;
use Digitalygo\Envai\Models\Team\TeamMember;
use Digitalygo\Envai\Models\Team\TeamMemberCreateRequest;
use Digitalygo\Envai\Models\Team\TeamUpdateRequest;
use PHPUnit\Framework\TestCase;

final class TeamTest extends TestCase
{
    public function test_team_hydrates_members_and_preserves_extra(): void
    {
        $team = Team::fromArray([
            'id' => 'team_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'Research team',
            'description' => 'Cross functional research',
            'orchestrator_id' => 'agt_01j5abcdefghjkmnpqrstvwxyz',
            'members' => [
                [
                    'agent_definition_id' => 'agt_01j5abcdefghjkmnpqrstvwxyA',
                    'name' => 'researcher',
                    'position' => 0,
                    'role' => 'research',
                ],
                [
                    'agent_definition_id' => 'agt_01j5abcdefghjkmnpqrstvwxyB',
                    'name' => 'writer',
                    'position' => 1,
                    'role' => null,
                ],
            ],
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:00:00+00:00',
            'budget_usd' => 50,
        ]);

        $this->assertSame('team_01j5abcdefghjkmnpqrstvwxyz', $team->id);
        $this->assertSame('Research team', $team->name);
        $this->assertSame('agt_01j5abcdefghjkmnpqrstvwxyz', $team->orchestratorId);
        $this->assertCount(2, $team->members);
        $this->assertInstanceOf(TeamMember::class, $team->members[0]);
        $this->assertSame('researcher', $team->members[0]->name);
        $this->assertSame(1, $team->members[1]->position);
        $this->assertNull($team->members[1]->role);
        $this->assertSame(['budget_usd' => 50], $team->extra);
    }

    public function test_team_member_hydrates_and_preserves_extra(): void
    {
        $member = TeamMember::fromArray([
            'agent_definition_id' => 'agt_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'researcher',
            'position' => 0,
            'role' => 'research',
            'joined_at' => '2026-08-17T14:00:00+00:00',
        ]);

        $this->assertSame('agt_01j5abcdefghjkmnpqrstvwxyz', $member->agentDefinitionId);
        $this->assertSame(0, $member->position);
        $this->assertSame(['joined_at' => '2026-08-17T14:00:00+00:00'], $member->extra);
    }

    public function test_team_create_request_serializes_members_and_omits_empty(): void
    {
        $withMembers = new TeamCreateRequest(
            name: 'Research team',
            orchestratorId: 'agt_01j5abcdefghjkmnpqrstvwxyz',
            members: [new TeamMemberCreateRequest('agt_01j5abcdefghjkmnpqrstvwxyA', 'research')],
        );

        $this->assertSame([
            'name' => 'Research team',
            'orchestrator_id' => 'agt_01j5abcdefghjkmnpqrstvwxyz',
            'members' => [
                ['agent_definition_id' => 'agt_01j5abcdefghjkmnpqrstvwxyA', 'role' => 'research'],
            ],
        ], $withMembers->toArray());

        $this->assertSame([
            'name' => 'Research team',
            'orchestrator_id' => 'agt_01j5abcdefghjkmnpqrstvwxyz',
        ], (new TeamCreateRequest('Research team', 'agt_01j5abcdefghjkmnpqrstvwxyz'))->toArray());
    }

    public function test_team_update_request_replaces_members_wholesale(): void
    {
        $clearing = TeamUpdateRequest::members([]);
        $replacing = new TeamUpdateRequest(
            name: OptionalField::absent(),
            description: OptionalField::absent(),
            orchestratorId: OptionalField::absent(),
            members: OptionalField::of([new TeamMemberCreateRequest('agt_01j5abcdefghjkmnpqrstvwxyA')]),
        );

        $this->assertSame(['members' => []], $clearing->toArray());
        $this->assertSame(['members' => [['agent_definition_id' => 'agt_01j5abcdefghjkmnpqrstvwxyA']]], $replacing->toArray());
    }

    public function test_team_update_request_distinguishes_absent_from_null_description(): void
    {
        $this->assertSame(['description' => null], TeamUpdateRequest::description(null)->toArray());
        $this->assertSame([], (new TeamUpdateRequest(
            name: OptionalField::absent(),
            description: OptionalField::absent(),
            orchestratorId: OptionalField::absent(),
            members: OptionalField::absent(),
        ))->toArray());
    }
}
