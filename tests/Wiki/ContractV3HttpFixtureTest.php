<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Http\HttpResponse;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ContractV3HttpFixtureTest extends TestCase
{
    public function test_actual_laravel_http_responses_match_pinned_schema_and_typed_negotiation(): void
    {
        $directory = dirname(__DIR__).'/fixtures/contract-v3-http/';
        $provenance = json_decode(file_get_contents($directory.'provenance.json'), true);
        self::assertSame('c27963d0848540679bccf30d2aeab354097b1a38', $provenance['backend_commit']);
        foreach ($provenance['files'] as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', $directory.$file));
        }
        foreach (['source.json', 'completion-pending.json', 'legacy-completion.json'] as $file) {
            $body = file_get_contents($directory.$file);
            $legacy = $file === 'legacy-completion.json';
            $source = $file === 'source.json';
            $version = $legacy ? 2 : 3;
            $headers = $legacy ? ['x-envai-contract-version' => ['2']] : json_decode(file_get_contents($directory.($source ? 'source-headers.json' : 'completion-headers.json')), true);
            if (! $legacy) {
                self::assertSame(['3'], $headers['x-envai-contract-version']);
            }
            $wire = json_decode($body);
            $schema = json_decode(json_encode(Yaml::parseFile(dirname(__DIR__, 2).'/docs/openapi.yaml')));
            $schema->{'$ref'} = '#/components/schemas/'.($source ? 'WikiSource' : 'Completion');
            self::assertTrue((new Validator)->validate($wire->data, $schema)->isValid(), $file);
            $transport = new ContractTestTransport;
            $transport->responses = [new HttpResponse(200, $headers, $body)];
            $client = new EnvaiClient(new EnvaiConfig('https://envai.example', new SensitiveString('synthetic'), contractVersion: $version), $transport);
            if ($source) {
                $model = $client->workspaceFiles()->sourceStatus($wire->data->workspace_id, $wire->data->file_id);
                self::assertSame($wire->data->file_id, $model->fileId);
            } else {
                $model = $client->completions()->get($wire->data->workspace_id, $wire->data->chat_id, $wire->data->id);
                self::assertSame($legacy ? 0.25 : null, $model->costUsd);
                if (! $legacy) {
                    self::assertSame(3, $model->terminalSnapshot->v);
                    self::assertSame(3, $model->terminalSnapshot->acceptedProtocolVersion);
                    self::assertSame('pending', $model->usageCost->status);
                    self::assertNull($model->usageCost->totals);
                }
            }
            self::assertSame([(string) $version], $transport->requests[0]->headers['x-envai-contract-version']);
        }
    }
}
