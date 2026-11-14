<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Streaming\Events\TurnCompletedEvent;
use Digitalygo\Envai\Tests\Streaming\Atp\FakeEventStreamConnection;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use PHPUnit\Framework\TestCase;

final class ChatRuntimeTest extends TestCase
{
    private const WORKSPACE = 'wsp_01';

    private const CHAT = Fixtures::CHAT_ID;

    public function test_events_opens_the_stream_with_the_cursor_and_bearer_header(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($transport);

        $stream = $client->chat(self::WORKSPACE, self::CHAT)->events(after: 5);

        $this->assertSame(self::CHAT, $stream->chatId());
        $this->assertCount(1, $transport->streamRequests);

        $streamRequest = $transport->streamRequests[0];

        parse_str((string) parse_url($streamRequest->uri, PHP_URL_QUERY), $query);
        $this->assertSame('5', $query['after']);
        $this->assertSame('text/event-stream', $streamRequest->headers['accept'][0]);
        $this->assertSame('Bearer sk-test-secret', $streamRequest->headers['authorization'][0]);
    }

    public function test_events_uses_zero_as_the_default_cursor(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($transport);

        $client->chat(self::WORKSPACE, self::CHAT)->events();

        parse_str((string) parse_url($transport->streamRequests[0]->uri, PHP_URL_QUERY), $query);
        $this->assertSame('0', $query['after']);
    }

    public function test_send_message_posts_the_chat_message_request(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse($this->topLevelResponse(['status' => 'accepted', 'turn_id' => Fixtures::TURN_ID]));

        $client = $this->client($transport);

        $accepted = $client->chat(self::WORKSPACE, self::CHAT)->sendMessage('Ciao');

        $this->assertSame('accepted', $accepted->status);
        $this->assertSame(Fixtures::TURN_ID, $accepted->turnId);

        $request = $transport->requests[0];

        $this->assertSame('POST', $request->method);
        $this->assertSame('/api/v1/atp/v3/chats/'.self::CHAT.'/messages', parse_url($request->uri, PHP_URL_PATH));
        $this->assertSame(['message' => 'Ciao'], json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_send_and_wait_round_trips_over_a_scripted_connection(): void
    {
        $transport = new FakeTransport;

        // ping proves the live subscription, then the simple turn delivers
        // the accepted turn events.
        $transport->connection = FakeEventStreamConnection::fromEnvelopes([
            Fixtures::ping(),
            ...Fixtures::simpleTurn(),
        ]);

        $transport->queueResponse($this->topLevelResponse(['status' => 'accepted', 'turn_id' => Fixtures::TURN_ID]));

        $client = $this->client($transport);

        $result = $client->chat(self::WORKSPACE, self::CHAT)->sendAndWait('Ciao');

        $this->assertSame(self::CHAT, $result->chatId);
        $this->assertSame(Fixtures::TURN_ID, $result->turnId);
        $this->assertInstanceOf(TurnCompletedEvent::class, $result->terminal);
        $this->assertSame(7, $result->lastSeq);
        $this->assertStringContainsString('analisi', (string) $result->orchestratorText);

        // The message was sent through the chat HTTP client.
        $messageRequest = $transport->requests[0];

        $this->assertSame('POST', $messageRequest->method);
        $this->assertSame('/api/v1/atp/v3/chats/'.self::CHAT.'/messages', parse_url($messageRequest->uri, PHP_URL_PATH));
        $this->assertSame(['message' => 'Ciao'], json_decode((string) $messageRequest->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_send_and_stream_yields_the_accepted_turn_events(): void
    {
        $transport = new FakeTransport;

        $transport->connection = FakeEventStreamConnection::fromEnvelopes([
            Fixtures::ping(),
            ...Fixtures::simpleTurn(),
        ]);

        $transport->queueResponse($this->topLevelResponse(['status' => 'accepted', 'turn_id' => Fixtures::TURN_ID]));

        $client = $this->client($transport);

        $events = iterator_to_array($client->chat(self::WORKSPACE, self::CHAT)->sendAndStream('Ciao'));

        $this->assertCount(7, $events);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_map(
            static fn ($event): int => $event->envelope->seq,
            $events,
        ));
    }

    public function test_model_and_attachment_policy_is_sent_by_every_send_helper(): void
    {
        foreach (['sendMessage', 'sendAndStream', 'sendAndWait'] as $method) {
            $transport = new FakeTransport;
            if ($method !== 'sendMessage') {
                $transport->connection = FakeEventStreamConnection::fromEnvelopes([Fixtures::ping(), ...Fixtures::simpleTurn()]);
            }
            $transport->queueResponse(new HttpResponse(200, ['X-Envai-Contract-Version' => '3'], json_encode(['protocol_version' => 3, 'contract_version' => 3, 'supported_contract_versions' => [2, 3], 'legacy_v2_mode' => 'historical_reads_replay_only', 'features' => ['chat_attachments'], 'families' => [], 'event_types' => [], 'tool_kinds' => []])));
            $transport->queueResponse($this->topLevelResponse(['status' => 'accepted', 'turn_id' => Fixtures::TURN_ID, 'attachment_ids' => ['att_b', 'att_a']]));
            $chat = $this->client($transport)->chat(self::WORKSPACE, self::CHAT);
            $result = $chat->$method('hi', ['att_b', 'att_a'], 'key-1', false, 'Vendor/Model');
            if ($result instanceof \Generator) {
                iterator_to_array($result);
            }
            $this->assertCount(2, $transport->requests);
            $request = $transport->requests[1];
            $this->assertSame([
                'message' => 'hi',
                'strict_model' => false,
                'model' => 'Vendor/Model',
                'attachment_ids' => ['att_b', 'att_a'],
            ], json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR));
            $this->assertSame(['key-1'], $request->headers['idempotency-key']);
            $this->assertArrayNotHasKey('x-envai-extensions', $request->headers);
            $this->assertCount($method === 'sendMessage' ? 0 : 1, $transport->streamRequests);

            $transport->queueResponse($this->topLevelResponse(['status' => 'accepted', 'turn_id' => Fixtures::TURN_ID]));
            $chat->sendMessage('next');
            $this->assertSame(['message' => 'next'], json_decode((string) $transport->requests[2]->body, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function test_stream_helpers_confirm_live_subscription_before_sending(): void
    {
        foreach (['sendAndStream', 'sendAndWait'] as $method) {
            $transport = new FakeTransport;
            $transport->connection = FakeEventStreamConnection::fromEnvelopes(Fixtures::simpleTurn());
            try {
                $chat = $this->client($transport)->chat(self::WORKSPACE, self::CHAT);
                $result = $chat->$method('hi', [], 'key-1', true, 'Vendor/Model');
                if ($result instanceof \Generator) {
                    iterator_to_array($result);
                }
                $this->fail('Unconfirmed stream sent a message.');
            } catch (TransportException) {
                $this->assertSame([], $transport->requests);
                $this->assertNotEmpty($transport->streamRequests);
            }
        }
    }

    private function client(FakeTransport $transport): EnvaiClient
    {
        $config = new EnvaiConfig(
            baseUrl: 'https://envai.example.com',
            apiKey: new SensitiveString('sk-test-secret'),
        );

        return new EnvaiClient($config, $transport);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function topLevelResponse(array $payload): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => ['application/json']], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
