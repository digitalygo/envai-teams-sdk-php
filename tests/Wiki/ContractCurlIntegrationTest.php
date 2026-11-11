<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\ContractCompatibilityException;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use Digitalygo\Envai\Tests\Http\Curl\CurlFunctionalTestCase;
use Digitalygo\Envai\Tests\Http\Curl\TestHttpServer;

final class ContractCurlIntegrationTest extends CurlFunctionalTestCase
{
    public function test_old_server_preflight_fails_before_mutation_over_native_curl(): void
    {
        $summary = $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->respond("HTTP/1.1 404 Not Found\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");

                return ['request' => $request];
            },
            function (string $base): void {
                $client = new EnvaiClient(new EnvaiConfig($base, new SensitiveString('synthetic')));
                try {
                    $client->chats()->sendMessage('c', new ChatMessageRequest('hello'));
                    self::fail('Old server accepted new-contract work.');
                } catch (ContractCompatibilityException) {
                    self::assertTrue(true);
                }
            },
        );
        self::assertStringStartsWith('GET /api/v1/atp/v3/capabilities HTTP/1.1', $summary['request']);
        self::assertStringContainsString('x-envai-contract-version: 3', strtolower($summary['request']));
    }

    public function test_upgrade_stream_handshake_maps_to_compatibility_error_over_native_curl(): void
    {
        $summary = $this->withServer(
            function (string $request, TestHttpServer $server): array {
                $server->respond("HTTP/1.1 426 Upgrade Required\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");

                return ['request' => $request];
            },
            function (string $base): void {
                $client = new EnvaiClient(new EnvaiConfig($base, new SensitiveString('synthetic'), contractVersion: 2));
                try {
                    $client->chats()->openLegacyEventStream('c');
                    self::fail('Upgrade-required stream accepted.');
                } catch (ContractCompatibilityException $exception) {
                    self::assertNotNull($exception->getPrevious());
                }
            },
        );
        self::assertStringStartsWith('GET /api/v1/chats/c/events?after=0 HTTP/1.1', $summary['request']);
        self::assertStringContainsString('x-envai-contract-version: 2', strtolower($summary['request']));
    }

    public function test_native_stream_ack_and_mixed_versions_are_preserved(): void
    {
        $wire = ContractVersionParsingTest::sample('turn.completed');
        $wire['v'] = 2;
        $wire['seq'] = 1;
        $new = $wire;
        $new['v'] = 3;
        $new['seq'] = 2;
        $new['data']['cost'] = null;
        $new['data']['usage']['cost_usd'] = null;
        $summary = $this->withServer(
            function (string $request, TestHttpServer $server) use ($wire, $new): array {
                $body = 'data: '.json_encode($wire)."\n\ndata: ".json_encode($new)."\n\n";
                $server->respond("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nX-Envai-Contract-Version: 3\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n".$body);

                return ['request' => $request];
            },
            function (string $base) use ($wire, $new): void {
                $client = new EnvaiClient(new EnvaiConfig($base.'/api/v1', new SensitiveString('synthetic')));
                $events = iterator_to_array($client->chat('w', 'cht_01ARZ3NDEKTSV4RRFFQ69G5FAV')->events(reconnect: false));
                self::assertCount(2, $events);
                self::assertSame($wire, $events[0]->raw());
                self::assertSame($new, $events[1]->raw());
                self::assertNull($events[1]->usage->costUsd);
            },
        );
        self::assertStringStartsWith('GET /api/v1/atp/v3/chats/', $summary['request']);
        self::assertStringContainsString('x-envai-contract-version: 3', strtolower($summary['request']));
    }

    public function test_native_completion_null_requires_exact_ack_before_hydration(): void
    {
        foreach (['3', '2', null] as $ack) {
            $this->withServer(
                function (string $request, TestHttpServer $server) use ($ack): array {
                    $wire = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/control-plane-http/completion-legacy.json'), true);
                    $wire['data']['cost_usd'] = null;
                    $body = json_encode($wire);
                    $server->respond("HTTP/1.1 200 OK\r\nContent-Type: application/json\r\n".($ack === null ? '' : 'X-Envai-Contract-Version: '.$ack."\r\n").'Content-Length: '.strlen($body)."\r\nConnection: close\r\n\r\n".$body);

                    return ['request' => $request];
                },
                function (string $base) use ($ack): void {
                    $client = new EnvaiClient(new EnvaiConfig($base, new SensitiveString('synthetic')));
                    try {
                        $completion = $client->completions()->get('w', 'c', 'turn');
                        self::assertSame('3', $ack);
                        self::assertNull($completion->costUsd);
                    } catch (ContractCompatibilityException) {
                        self::assertNotSame('3', $ack);
                    }
                },
            );
        }
    }
}
