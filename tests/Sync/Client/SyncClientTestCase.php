<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Tests\Client\FakeTransport;
use PHPUnit\Framework\TestCase;

/**
 * Shared harness for the sync-over-HTTP tests: an EnvaiClient over the
 * scripted FakeTransport with OpenAPI-shaped response payloads.
 */
abstract class SyncClientTestCase extends TestCase
{
    protected const BASE_URL = 'https://envai.example.com';

    protected function client(FakeTransport $transport): EnvaiClient
    {
        $config = new EnvaiConfig(
            baseUrl: self::BASE_URL,
            apiKey: new SensitiveString('sk-test-secret'),
        );

        return new EnvaiClient($config, $transport);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function envelope(HttpResponse $response): HttpResponse
    {
        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function envelopeResponse(array $payload): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => ['application/json']], json_encode(['data' => $payload], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function collectionResponse(array $items): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => ['application/json']], json_encode(['data' => $items], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function topLevelResponse(array $payload): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => ['application/json']], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    protected function noContentResponse(): HttpResponse
    {
        return new HttpResponse(204);
    }
}
