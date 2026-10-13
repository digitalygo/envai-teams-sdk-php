<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Compiler;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Contracts\EventStreamTransport;
use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Definitions\Compiler\DefinitionCompiler;
use Digitalygo\Envai\Definitions\Manifest\CanonicalManifestHasher;
use Digitalygo\Envai\Http\Client\JsonCodec;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\StreamRequest;
use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Models\Tool\UserTool;
use Digitalygo\Envai\Models\Tool\UserToolCreateRequest;
use Digitalygo\Envai\Models\Tool\UserToolUpdateRequest;
use Digitalygo\Envai\Streaming\Atp\Payload;
use Digitalygo\Envai\Sync\Client\ClientRemoteReader;
use Digitalygo\Envai\Sync\Client\ClientRemoteWriter;
use Digitalygo\Envai\Sync\Planner\PayloadNormalizer;
use Digitalygo\Envai\Sync\State\File\FileSyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\Pdo\PdoSyncStateStore;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Tests\Definitions\DefinitionsTestCase;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\MapClientToolFactory;
use Digitalygo\Envai\Tools\Execution\ToolArgumentsValidator;
use PHPUnit\Framework\Attributes\DataProvider;

final class ToolSchemaRoundTripTest extends DefinitionsTestCase
{
    #[DataProvider('schemas')]
    public function test_authoring_compilation_sync_persistence_http_and_validation_agree(array $schema, \stdClass $wire, JsonObject $good, ?JsonObject $bad): void
    {
        $expected = CanonicalJsonSerializer::serialize(Payload::typed($wire));
        $transport = new SchemaEchoTransport;
        $client = new EnvaiClient(new EnvaiConfig(baseUrl: 'https://example.invalid/api/v1', apiKey: new SensitiveString('test')), $transport);
        $codec = new JsonCodec;
        foreach (['create', 'update'] as $method) {
            $response = $method === 'create'
                ? $client->tools()->create(new UserToolCreateRequest('echo', $schema))
                : $client->tools()->update('echo', UserToolUpdateRequest::parameters($schema));
            $this->assertSchema($expected, json_decode($transport->last->body)->parameters);
            $this->assertSchema($expected, json_decode($codec->encodeRequestBody($response->parameters)));
        }

        $directory = $this->makeEnvaiLayout(tools: '<?php return ["echo" => \\'.FlexibleTool::class.'::class];');
        $result = (new DefinitionCompiler)->compile($directory, new MapClientToolFactory([FlexibleTool::class => new FlexibleTool(schema: $schema)]));
        $this->assertTrue($result->valid, json_encode($result->violations, JSON_THROW_ON_ERROR));
        $payload = $result->manifest->tool('echo')->toRemotePayload();
        $normalized = PayloadNormalizer::normalize(ResourceType::Tool, $payload);
        $hasher = new CanonicalManifestHasher;
        $hash = $hasher->resourceHash('tool', 'echo', $payload);
        $this->assertSame($hash, $hasher->resourceHash('tool', 'echo', $normalized));
        $this->assertSchema($expected, json_decode($codec->encodeRequestBody($normalized['parameters'])));

        foreach (['file', 'sqlite'] as $driver) {
            if ($driver === 'sqlite' && ! extension_loaded('pdo_sqlite')) {
                continue;
            }
            $store = $driver === 'file' ? new FileSyncStateStore($directory.'/state.json') : new PdoSyncStateStore(new \PDO('sqlite:'.$directory.'/state.sqlite'));
            if ($store instanceof PdoSyncStateStore) {
                $store->ensureSchema();
            }
            $store->saveResource(new ManagedResourceState('test', ResourceType::Tool, 'echo', lastAppliedPayload: new JsonObject($normalized)));
            unset($store);
            $reopened = $driver === 'file' ? new FileSyncStateStore($directory.'/state.json') : new PdoSyncStateStore(new \PDO('sqlite:'.$directory.'/state.sqlite'));
            if ($reopened instanceof PdoSyncStateStore) {
                $reopened->ensureSchema();
            }
            $restored = PayloadNormalizer::normalize(ResourceType::Tool, $reopened->getResource('test', ResourceType::Tool, 'echo')->lastAppliedPayload);
            $this->assertSame($hash, $hasher->resourceHash('tool', 'echo', $restored));
            $this->assertSchema($expected, json_decode($codec->encodeRequestBody($restored['parameters'])));
        }

        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());
        foreach (['create', 'update'] as $method) {
            $response = $method === 'create' ? $writer->createTool($normalized) : $writer->updateTool('echo', $normalized);
            $this->assertSchema($expected, json_decode($transport->last->body)->parameters);
            $this->assertSchema($expected, json_decode($codec->encodeRequestBody($response['parameters'])));
            $this->assertSame([], ToolArgumentsValidator::validate($good, $response['parameters']));
            if ($bad !== null) {
                $this->assertNotSame([], ToolArgumentsValidator::validate($bad, $response['parameters']));
            }
        }
    }

    public function test_empty_and_numeric_root_schema_maps_encode_as_objects(): void
    {
        $codec = new JsonCodec;
        foreach ([[], [0 => new \stdClass]] as $schema) {
            foreach ([new UserToolCreateRequest('echo', $schema), UserToolUpdateRequest::parameters($schema)] as $request) {
                $wire = json_decode($codec->encodeRequestBody($request->toArray()));
                $this->assertInstanceOf(\stdClass::class, $wire->parameters);
                $this->assertSame(count($schema), count(get_object_vars($wire->parameters)));
            }
        }
        $this->assertSame('{}', $codec->encodeRequestBody(new JsonObject([])));
        $this->assertSame('{"0":{}}', $codec->encodeRequestBody(new JsonObject([0 => new JsonObject([])])));
    }

    public function test_invalid_unused_schema_is_a_compilation_violation(): void
    {
        $directory = $this->makeEnvaiLayout(tools: '<?php return ["echo" => \\'.FlexibleTool::class.'::class];');
        $tool = new FlexibleTool(schema: ['type' => 'object', 'properties' => ['optional' => ['type' => 17]]]);
        $result = (new DefinitionCompiler)->compile($directory, new MapClientToolFactory([FlexibleTool::class => $tool]));
        $this->assertFalse($result->valid);
        $this->assertSame('tool.invalid_schema', $result->violations[0]->code);
        $this->assertSame([], $result->manifest->tools);
    }

    public function test_numeric_and_empty_root_schema_maps_survive_all_read_paths(): void
    {
        $codec = new JsonCodec;
        $transport = new SchemaEchoTransport;
        $client = new EnvaiClient(new EnvaiConfig(baseUrl: 'https://example.invalid/api/v1', apiKey: new SensitiveString('test')), $transport);
        $reader = new ClientRemoteReader($client->tools(), $client->agents(), $client->teams(), $client->models());
        foreach ([new \stdClass, (object) ['0' => new \stdClass]] as $schema) {
            $transport->readSchema = $schema;
            $expected = CanonicalJsonSerializer::serialize(Payload::typed($schema));
            $rows = [
                $client->tools()->get('echo')->parameters,
                $client->tools()->list()->items[0]->parameters,
                $reader->listTools()[0]['parameters'],
                $codec->decodeTopLevelInto(json_encode($transport->row($schema), JSON_THROW_ON_ERROR), UserTool::class)->parameters,
            ];
            foreach ($rows as $parameters) {
                $normalized = PayloadNormalizer::normalize(ResourceType::Tool, ['name' => 'echo', 'parameters' => $parameters]);
                $wire = json_decode($codec->encodeRequestBody((new UserToolCreateRequest('echo', $normalized['parameters']))->toArray()));
                $this->assertSchema($expected, $wire->parameters);
            }
        }
    }

    private function assertSchema(string $expected, mixed $schema): void
    {
        $this->assertSame($expected, CanonicalJsonSerializer::serialize(Payload::typed($schema)));
    }

    public static function schemas(): iterable
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__, 2).'/fixtures/current-server/schema-wire-cases.json'), false, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            foreach (['arrays', 'objects', 'typed'] as $form) {
                $schema = match ($form) {
                    'objects' => get_object_vars($case->schema),
                    'typed' => Payload::typed($case->schema)->data,
                    default => self::authoringArray($case->schema),
                };
                yield $case->name.' '.$form => [$schema, $case->schema, Payload::typed($case->good ?? new \stdClass), isset($case->bad) ? Payload::typed($case->bad) : null];
            }
        }
    }

    private static function authoringArray(mixed $value, bool $literal = false): mixed
    {
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::authoringArray($item, $literal), $value);
        }
        if (! $value instanceof \stdClass) {
            return $value;
        }
        $members = [];
        foreach (get_object_vars($value) as $key => $item) {
            $members[$key] = self::authoringArray($item, $literal || in_array($key, ['const', 'enum', 'default', 'examples'], true));
        }

        return $literal && ($members === [] || array_is_list($members)) ? (object) $members : $members;
    }
}

final class SchemaEchoTransport implements EventStreamTransport, HttpTransport
{
    public HttpRequest $last;

    public ?\stdClass $readSchema = null;

    public function send(HttpRequest $request): HttpResponse
    {
        $this->last = $request;
        if ($this->readSchema !== null) {
            $row = $this->row($this->readSchema);
            $data = str_ends_with($request->uri, '/echo') ? $row : [$row];
        } else {
            $body = json_decode($request->body, false, 512, JSON_THROW_ON_ERROR);
            $data = $this->row($body->parameters);
        }

        return new HttpResponse(200, ['content-type' => ['application/json']], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
    }

    public function row(\stdClass $parameters): array
    {
        return [
            'id' => 'twl_test', 'name' => 'echo', 'kind' => 'client', 'description' => 'Flexible test tool.',
            'parameters' => $parameters, 'timeout_seconds' => 120,
            'created_at' => '2026-10-13T00:00:00Z', 'updated_at' => '2026-10-13T00:00:00Z',
        ];
    }

    public function open(StreamRequest $request): EventStreamConnection
    {
        throw new \LogicException('No streaming in this test.');
    }
}
