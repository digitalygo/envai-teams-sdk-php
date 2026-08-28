<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Client;

use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Sync\Client\ClientRemoteWriter;
use Digitalygo\Envai\Tests\Client\FakeTransport;

final class ClientRemoteWriterTest extends SyncClientTestCase
{
    public function test_create_tool_sends_the_request_dto_and_returns_the_remote_to_array(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->envelopeResponse([
            'id' => 'twl_query_db',
            'name' => 'query_db',
            'kind' => 'client',
            'description' => 'Query the database',
            'parameters' => ['type' => 'object', 'properties' => []],
            'timeout_seconds' => 30,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ]));

        $client = $this->client($transport);
        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());

        $result = $writer->createTool([
            'name' => 'query_db',
            'kind' => 'client',
            'description' => 'Query the database',
            'parameters' => ['type' => 'object', 'properties' => []],
            'timeout_seconds' => 30,
            'extra_unknown_key' => 'dropped by the DTO',
        ]);

        $request = $transport->requests[0];

        $this->assertSame('POST', $request->method);
        $this->assertSame('/api/v1/tools', parse_url($request->uri, PHP_URL_PATH));
        $this->assertSame('twl_query_db', $result['id']);
        $this->assertSame('2026-01-01T00:00:00Z', $result['updated_at']);
        $this->assertSame([
            'name' => 'query_db',
            'kind' => 'client',
            'parameters' => ['type' => 'object', 'properties' => []],
            'timeout_seconds' => 30,
            'description' => 'Query the database',
        ], json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_update_tool_is_keyed_by_name_and_sends_only_updatable_fields(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->envelopeResponse([
            'id' => 'twl_query_db',
            'name' => 'query_db',
            'kind' => 'client',
            'description' => 'Updated description',
            'parameters' => ['type' => 'object', 'properties' => []],
            'timeout_seconds' => 45,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-02T00:00:00Z',
        ]));

        $client = $this->client($transport);
        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());

        $result = $writer->updateTool('query_db', [
            'name' => 'query_db',
            'kind' => 'client',
            'description' => 'Updated description',
            'parameters' => ['type' => 'object', 'properties' => []],
            'timeout_seconds' => 45,
        ]);

        $request = $transport->requests[0];

        $this->assertSame('PUT', $request->method);
        $this->assertSame('/api/v1/tools/query_db', parse_url($request->uri, PHP_URL_PATH));
        $this->assertSame('2026-01-02T00:00:00Z', $result['updated_at']);
        // name and kind are server-owned and must never be serialized.
        $this->assertSame([
            'description' => 'Updated description',
            'parameters' => ['type' => 'object', 'properties' => []],
            'timeout_seconds' => 45,
        ], json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_delete_tool_is_keyed_by_name(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->noContentResponse());

        $client = $this->client($transport);
        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());

        $writer->deleteTool('retired_tool');

        $request = $transport->requests[0];

        $this->assertSame('DELETE', $request->method);
        $this->assertSame('/api/v1/tools/retired_tool', parse_url($request->uri, PHP_URL_PATH));
    }

    public function test_create_agent_builds_the_full_request_dto(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->envelopeResponse([
            'id' => 'agt_researcher',
            'user_id' => 'usr_test',
            'name' => 'researcher',
            'description' => null,
            'system_prompt' => 'You research.',
            'model' => 'anthropic/claude-haiku-4-5',
            'mode' => 'subagent',
            'tools_enabled' => [],
            'external_tools_enabled' => [['name' => 'query_db']],
            'permissions' => [],
            'can_write_knowledge' => false,
            'can_write_skills' => false,
            'config' => ['temperature' => 0.2],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ]));

        $client = $this->client($transport);
        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());

        $result = $writer->createAgent([
            'name' => 'researcher',
            'system_prompt' => 'You research.',
            'mode' => 'subagent',
            'description' => null,
            'model' => 'anthropic/claude-haiku-4-5',
            'external_tools_enabled' => [['name' => 'query_db']],
            'config' => ['temperature' => 0.2],
        ]);

        $request = $transport->requests[0];

        $this->assertSame('POST', $request->method);
        $this->assertSame('/api/v1/agents', parse_url($request->uri, PHP_URL_PATH));
        $this->assertSame('agt_researcher', $result['id']);
        $this->assertSame([
            'name' => 'researcher',
            'system_prompt' => 'You research.',
            'mode' => 'subagent',
            'can_write_knowledge' => false,
            'can_write_skills' => false,
            'model' => 'anthropic/claude-haiku-4-5',
            'external_tools_enabled' => [['name' => 'query_db']],
            'config' => ['temperature' => 0.2],
        ], json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_update_agent_is_keyed_by_remote_id(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->envelopeResponse([
            'id' => 'agt_researcher',
            'user_id' => 'usr_test',
            'name' => 'researcher',
            'description' => null,
            'system_prompt' => 'You research harder.',
            'model' => null,
            'mode' => 'subagent',
            'tools_enabled' => [],
            'external_tools_enabled' => [],
            'permissions' => [],
            'can_write_knowledge' => false,
            'can_write_skills' => false,
            'config' => [],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-02T00:00:00Z',
        ]));

        $client = $this->client($transport);
        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());

        $result = $writer->updateAgent('agt_researcher', [
            'name' => 'researcher',
            'system_prompt' => 'You research harder.',
            'mode' => 'subagent',
        ]);

        $request = $transport->requests[0];

        $this->assertSame('PUT', $request->method);
        $this->assertSame('/api/v1/agents/agt_researcher', parse_url($request->uri, PHP_URL_PATH));
        $this->assertSame('2026-01-02T00:00:00Z', $result['updated_at']);
        $this->assertSame([
            'name' => 'researcher',
            'system_prompt' => 'You research harder.',
            'mode' => 'subagent',
        ], json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_create_team_builds_members_and_returns_remote(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->envelopeResponse([
            'id' => 'team_forecasting',
            'user_id' => 'usr_test',
            'name' => 'forecasting',
            'description' => null,
            'orchestrator_id' => 'agt_orchestrator',
            'members' => [['agent_definition_id' => 'agt_researcher', 'name' => 'researcher', 'position' => 0, 'role' => 'researcher']],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ]));

        $client = $this->client($transport);
        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());

        $result = $writer->createTeam([
            'name' => 'forecasting',
            'orchestrator_id' => 'agt_orchestrator',
            'members' => [['agent_definition_id' => 'agt_researcher', 'role' => 'researcher']],
        ]);

        $request = $transport->requests[0];

        $this->assertSame('POST', $request->method);
        $this->assertSame('/api/v1/teams', parse_url($request->uri, PHP_URL_PATH));
        $this->assertSame('team_forecasting', $result['id']);
        $this->assertSame([
            'name' => 'forecasting',
            'orchestrator_id' => 'agt_orchestrator',
            'members' => [['agent_definition_id' => 'agt_researcher', 'role' => 'researcher']],
        ], json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_update_team_is_keyed_by_remote_id(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->envelopeResponse([
            'id' => 'team_forecasting',
            'user_id' => 'usr_test',
            'name' => 'forecasting',
            'description' => null,
            'orchestrator_id' => 'agt_orchestrator',
            'members' => [],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-02T00:00:00Z',
        ]));

        $client = $this->client($transport);
        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());

        $result = $writer->updateTeam('team_forecasting', [
            'name' => 'forecasting',
            'orchestrator_id' => 'agt_orchestrator',
        ]);

        $request = $transport->requests[0];

        $this->assertSame('PUT', $request->method);
        $this->assertSame('/api/v1/teams/team_forecasting', parse_url($request->uri, PHP_URL_PATH));
        $this->assertSame('2026-01-02T00:00:00Z', $result['updated_at']);
        $this->assertSame([
            'name' => 'forecasting',
            'orchestrator_id' => 'agt_orchestrator',
        ], json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_read_remote_maps_tools_by_name_and_agents_by_id(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->envelopeResponse([
            'id' => 'twl_query_db',
            'name' => 'query_db',
            'kind' => 'client',
            'description' => 'Query',
            'parameters' => ['type' => 'object', 'properties' => []],
            'timeout_seconds' => 30,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ]));

        $client = $this->client($transport);
        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());

        $tool = $writer->readRemote('tool', 'query_db');
        $this->assertSame('twl_query_db', $tool['id']);
        $this->assertSame('/api/v1/tools/query_db', parse_url($transport->requests[0]->uri, PHP_URL_PATH));

        $transport->queueResponse($this->envelopeResponse([
            'id' => 'agt_researcher',
            'user_id' => 'usr_test',
            'name' => 'researcher',
            'description' => null,
            'system_prompt' => 'You research.',
            'model' => null,
            'mode' => 'subagent',
            'tools_enabled' => [],
            'external_tools_enabled' => [],
            'permissions' => [],
            'can_write_knowledge' => false,
            'can_write_skills' => false,
            'config' => [],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ]));

        $agent = $writer->readRemote('agent', 'agt_researcher');
        $this->assertSame('agt_researcher', $agent['id']);
        $this->assertSame('/api/v1/agents/agt_researcher', parse_url($transport->requests[1]->uri, PHP_URL_PATH));
    }

    public function test_read_remote_maps_404_to_null(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(404, ['content-type' => ['application/json']], json_encode(['error' => ['type' => 'not_found', 'message' => 'Tool not found.']], JSON_THROW_ON_ERROR)));

        $client = $this->client($transport);
        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());

        $this->assertNull($writer->readRemote('tool', 'missing_tool'));
    }

    public function test_read_remote_unknown_type_throws(): void
    {
        $client = $this->client(new FakeTransport);
        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());

        $this->expectException(\InvalidArgumentException::class);

        $writer->readRemote('widget', 'w1');
    }
}
