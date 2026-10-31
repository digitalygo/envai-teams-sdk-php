<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Http\Client\StrictValidationTransport;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Http\MultipartField;
use Digitalygo\Envai\Http\MultipartFile;
use Digitalygo\Envai\Models\Agent\AgentCreateRequest;
use Digitalygo\Envai\Models\Agent\AgentMode;
use Digitalygo\Envai\Tests\Client\FakeTransport;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class StrictValidationTransportTest extends TestCase
{
    public function test_shared_strict_responses_and_legacy_control(): void
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/../../fixtures/parity/schema-fixtures.json'), false, 512, JSON_THROW_ON_ERROR);
        foreach ($fixtures->response_cases as $name => $body) {
            $fake = new FakeTransport;
            $fake->queueResponse(new HttpResponse(200, [], json_encode($body, JSON_THROW_ON_ERROR)));
            $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test-key'), strictValidation: true), $fake);
            try {
                $agent = $client->agents()->get($fixtures->agent_id);
                self::assertSame('valid', $name);
                self::assertSame('researcher', $agent->name);
            } catch (ProtocolViolationException) {
                self::assertNotSame('valid', $name);
            }
        }
        $body = $fixtures->response_cases->valid;
        $body->data->name = str_repeat('x', 256);
        $fake = new FakeTransport;
        $fake->queueResponse(new HttpResponse(200, [], json_encode($body, JSON_THROW_ON_ERROR)));
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test-key')), $fake);
        self::assertSame(256, strlen($client->agents()->get($fixtures->agent_id)->name));
    }

    public function test_all_canonical_operations_are_indexed_and_malformed_mutation_has_no_effects(): void
    {
        $fake = new FakeTransport;
        $strict = new StrictValidationTransport($fake, new UriResolver('https://example.test'));
        self::assertCount(76, $strict->operationIds());
        try {
            $strict->send(new HttpRequest('POST', 'https://example.test/api/v1/agents', body: '{}'));
            self::fail('Invalid request accepted');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $fake->requests);
            self::assertCount(0, $fake->preflightRequests);
        }
    }

    public function test_public_client_rejects_missing_agent_fields(): void
    {
        $fake = new FakeTransport;
        $fake->queueResponse(new HttpResponse(200, [], '{"data":{}}'));
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test-key'), strictValidation: true), $fake);
        $this->expectException(ProtocolViolationException::class);
        $client->agents()->get('agt_01ARZ3NDEKTSV4RRFFQ69G5FAV');
    }

    public function test_strict_public_mutation_rejects_before_any_network(): void
    {
        $fake = new FakeTransport;
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test-key'), strictValidation: true), $fake);
        try {
            $client->agents()->create(new AgentCreateRequest(str_repeat('x', 256), 'prompt', AgentMode::Primary));
            self::fail('Invalid mutation accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $fake->requests);
            self::assertSame([], $fake->preflightRequests);
        }
    }

    public function test_multipart_array_cardinality_and_no_file_reads(): void
    {
        $fake = new FakeTransport;
        $strict = new StrictValidationTransport($fake, new UriResolver('https://example.test'));
        $parts = [new MultipartField('prompt', 'hello'), new MultipartField('model_code', 'fixture')];
        $parts[] = MultipartFile::fromPath('files[]', '/does-not-exist.png');
        $fake->queueResponse(new HttpResponse(422, [], '{}'));
        self::assertSame(422, $strict->send(new HttpRequest('POST', 'https://example.test/api/v1/tests/images/generate', body: new MultipartBody($parts)))->status);
        foreach (range(1, 4) as $unused) {
            $parts[] = MultipartFile::fromPath('files[]', '/does-not-exist.png');
        }
        try {
            $strict->send(new HttpRequest('POST', 'https://example.test/api/v1/tests/images/generate', body: new MultipartBody($parts)));
            self::fail('Five files accepted');
        } catch (\InvalidArgumentException) {
            self::assertCount(1, $fake->requests);
        }
    }

    public function test_native_owned_header_query_and_legacy_boundaries(): void
    {
        $fake = new FakeTransport;
        $strict = new StrictValidationTransport($fake, new UriResolver('https://example.test'));
        $fake->queueResponse(new HttpResponse(422, [], '{}'));
        // The inner contract transport supplies the selected header; it is not a caller requirement.
        $uri = 'https://example.test/api/v1/atp/v3/chats/cht_01ARZ3NDEKTSV4RRFFQ69G5FAV/tasks/task/cancel';
        self::assertSame(422, $strict->send(new HttpRequest('POST', $uri, body: '{}'))->status);
        $fake->queueResponse(new HttpResponse(422, [], '{}'));
        $completions = 'https://example.test/api/v1/workspaces/wsp_01ARZ3NDEKTSV4RRFFQ69G5FAV/chats/cht_01ARZ3NDEKTSV4RRFFQ69G5FAV/completions';
        self::assertSame(422, $strict->send(HttpRequest::get($completions.'?page=2'))->status);
        try {
            $strict->send(HttpRequest::get($completions.'?page=0'));
            self::fail('Invalid page accepted');
        } catch (\InvalidArgumentException) {
            self::assertCount(2, $fake->requests);
        }
        $legacy = new StrictValidationTransport($fake, new UriResolver('https://example.test'), 2);
        $fake->queueResponse(new HttpResponse(200, [], '{}'));
        self::assertSame(200, $legacy->send(HttpRequest::get($completions))->status);
    }

    public function test_every_canonical_json_success_schema_compiles_offline(): void
    {
        $document = Yaml::parseFile(__DIR__.'/../../../docs/openapi.yaml', Yaml::PARSE_OBJECT_FOR_MAP);
        $validator = new Validator;
        $document->{'$schema'} = 'https://json-schema.org/draft/2020-12/schema';
        $document->{'$id'} = 'https://envai.invalid/test/openapi.json';
        $validator->resolver()->registerRaw($document);
        $count = 0;
        foreach ($document->paths as $path => $item) {
            foreach ($item as $method => $operation) {
                foreach ($operation->responses ?? [] as $status => $response) {
                    if ((int) $status >= 200 && (int) $status < 300 && isset($response->content->{'application/json'}->schema)) {
                        $schema = 'https://envai.invalid/test/openapi.json#/paths/'.str_replace(['~', '/'], ['~0', '~1'], $path).'/'.$method.'/responses/'.$status.'/content/application~1json/schema';
                        self::assertInstanceOf(ValidationResult::class, $validator->validate(null, str_replace(['{', '}'], ['%7B', '%7D'], $schema)));
                        $count++;
                    }
                }
            }
        }
        self::assertSame(62, $count);
    }

    public function test_runtime_schema_is_exact_canonical_mirror_and_included_in_distribution(): void
    {
        self::assertSame(file_get_contents(__DIR__.'/../../../docs/openapi.yaml'), file_get_contents(__DIR__.'/../../../resources/http/openapi.yaml'));
        $attributes = file_get_contents(__DIR__.'/../../../.gitattributes');
        self::assertStringNotContainsString('/resources/ export-ignore', $attributes);
    }

    public function test_strict_capabilities_remain_forward_compatible_with_boolean_maps(): void
    {
        foreach ([['future_feature'], (object) ['future_feature' => true, 'disabled' => false], (object) ['future_feature' => 'invalid']] as $features) {
            $fake = new FakeTransport;
            $fake->queueResponse(new HttpResponse(200, ['X-Envai-Contract-Version' => '3'], json_encode([
                'protocol_version' => 3, 'contract_version' => 3, 'supported_contract_versions' => [2, 3],
                'legacy_v2_mode' => 'historical_reads_replay_only', 'families' => ['future_family'],
                'event_types' => [], 'tool_kinds' => [], 'features' => $features,
            ], JSON_THROW_ON_ERROR)));
            $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test-key'), strictValidation: true), $fake);
            try {
                $capabilities = $client->capabilities()->getCapabilities();
                self::assertTrue($capabilities->supports('future_feature'));
                self::assertSame(['future_family'], $capabilities->families);
                self::assertNotSame('invalid', is_object($features) ? $features->future_feature : null);
            } catch (ProtocolViolationException) {
                self::assertIsObject($features);
                self::assertSame('invalid', $features->future_feature);
            }
        }
    }
}
