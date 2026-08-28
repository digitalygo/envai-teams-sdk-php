<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Client;

use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Sync\Client\ClientRemoteReader;
use Digitalygo\Envai\Tests\Client\FakeTransport;

final class ClientRemoteReaderTest extends SyncClientTestCase
{
    public function test_list_tools_returns_the_dto_to_array_shape(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->collectionResponse([
            [
                'id' => 'twl_query_db',
                'name' => 'query_db',
                'kind' => 'client',
                'description' => 'Query the database',
                'parameters' => ['type' => 'object', 'properties' => []],
                'timeout_seconds' => 30,
                'created_at' => '2026-01-01T00:00:00Z',
                'updated_at' => '2026-01-01T00:00:00Z',
            ],
        ]));

        $client = $this->client($transport);
        $reader = new ClientRemoteReader($client->tools(), $client->agents(), $client->teams(), $client->models());

        $tools = $reader->listTools();

        $this->assertCount(1, $tools);
        $this->assertSame('query_db', $tools[0]['name']);
        $this->assertSame('twl_query_db', $tools[0]['id']);
        $this->assertSame('client', $tools[0]['kind']);
        $this->assertSame(['type' => 'object', 'properties' => []], $tools[0]['parameters']);
        $this->assertSame(30, $tools[0]['timeout_seconds']);

        $request = $transport->requests[0];

        $this->assertSame('GET', $request->method);
        $this->assertSame('/api/v1/tools', parse_url($request->uri, PHP_URL_PATH));
    }

    public function test_list_agents_and_teams_return_the_dto_shapes(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->collectionResponse([
            [
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
            ],
        ]));
        $transport->queueResponse($this->collectionResponse([
            [
                'id' => 'team_forecasting',
                'user_id' => 'usr_test',
                'name' => 'forecasting',
                'description' => null,
                'orchestrator_id' => 'agt_orchestrator',
                'members' => [],
                'created_at' => '2026-01-01T00:00:00Z',
                'updated_at' => '2026-01-01T00:00:00Z',
            ],
        ]));

        $client = $this->client($transport);
        $reader = new ClientRemoteReader($client->tools(), $client->agents(), $client->teams(), $client->models());

        $agents = $reader->listAgents();
        $teams = $reader->listTeams();

        $this->assertSame('agt_researcher', $agents[0]['id']);
        $this->assertSame('subagent', $agents[0]['mode']);
        $this->assertSame('team_forecasting', $teams[0]['id']);
        $this->assertSame('agt_orchestrator', $teams[0]['orchestrator_id']);
        $this->assertSame('/api/v1/agents', parse_url($transport->requests[0]->uri, PHP_URL_PATH));
        $this->assertSame('/api/v1/teams', parse_url($transport->requests[1]->uri, PHP_URL_PATH));
    }

    public function test_list_model_ids_extracts_the_catalog_ids(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->topLevelResponse([
            'object' => 'list',
            'data' => [
                ['id' => 'anthropic/claude-sonnet-4-5', 'object' => 'model', 'created' => 1720000000, 'owned_by' => 'anthropic', 'type' => 'llm', 'deprecated' => false, 'sunset_at' => null],
                ['id' => 'anthropic/claude-haiku-4-5', 'object' => 'model', 'created' => 1720000001, 'owned_by' => 'anthropic', 'type' => 'llm', 'deprecated' => false, 'sunset_at' => null],
                ['id' => 'team:forecasting', 'object' => 'model', 'created' => 1720000002, 'owned_by' => 'envai', 'type' => 'team', 'deprecated' => false, 'sunset_at' => null, 'team_id' => 'team_1'],
            ],
            'defaults' => ['orchestrator' => 'anthropic/claude-sonnet-4-5', 'subagent' => 'anthropic/claude-haiku-4-5'],
        ]));

        $client = $this->client($transport);
        $reader = new ClientRemoteReader($client->tools(), $client->agents(), $client->teams(), $client->models());

        $ids = $reader->listModelIds();

        $this->assertSame([
            'anthropic/claude-sonnet-4-5',
            'anthropic/claude-haiku-4-5',
            'team:forecasting',
        ], $ids);
    }

    public function test_list_model_ids_returns_null_when_the_catalog_read_fails(): void
    {
        $transport = new FakeTransport;
        $transport->queueException(new TransportException('Catalog endpoint unreachable.'));

        $client = $this->client($transport);
        $reader = new ClientRemoteReader($client->tools(), $client->agents(), $client->teams(), $client->models());

        $this->assertNull($reader->listModelIds());
    }

    public function test_list_model_ids_returns_null_on_http_error(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(500, [], json_encode(['error' => ['type' => 'server_error', 'message' => 'boom']], JSON_THROW_ON_ERROR)));

        $client = $this->client($transport);
        $reader = new ClientRemoteReader($client->tools(), $client->agents(), $client->teams(), $client->models());

        $this->assertNull($reader->listModelIds());
    }
}
