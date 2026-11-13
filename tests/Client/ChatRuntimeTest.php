<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\ApiException;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
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

    public function test_model_is_sent_per_turn_by_all_helpers(): void
    {
        foreach (['sendMessage', 'sendAndStream', 'sendAndWait'] as $method) {
            $transport = new FakeTransport;
            $transport->connection = FakeEventStreamConnection::fromEnvelopes([Fixtures::ping(), ...Fixtures::simpleTurn()]);
            $transport->queueResponse($this->topLevelResponse(['status' => 'accepted', 'turn_id' => Fixtures::TURN_ID]));
            $chat = $this->client($transport)->chat(self::WORKSPACE, self::CHAT);
            $result = $chat->$method('hi', [], 'key-1', null, 'Vendor/Model');
            if ($result instanceof \Generator) {
                iterator_to_array($result);
            }
            $wire = json_decode((string) $transport->requests[0]->body, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['message' => 'hi', 'model' => 'Vendor/Model'], $wire);
            $this->assertSame('key-1', $transport->requests[0]->headers['idempotency-key'][0]);
            $transport->queueResponse($this->topLevelResponse(['status' => 'accepted', 'turn_id' => Fixtures::TURN_ID]));
            $chat->sendMessage('next');
            $this->assertSame(['message' => 'next'], json_decode((string) $transport->requests[1]->body, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function test_model_errors_are_preserved_without_resending(): void
    {
        foreach (['invalid_model' => 422, 'unknown_model' => 422, 'model_unavailable' => 422, 'model_incompatible' => 422, 'model_catalog_unavailable' => 503, 'idempotency_conflict' => 409] as $code => $status) {
            $transport = new FakeTransport;
            $transport->queueResponse(new HttpResponse($status, [], json_encode(['error' => ['type' => $status === 422 ? 'validation_error' : ($status === 503 ? 'server_error' : 'conflict'), 'code' => $code, 'message' => 'rejected']])));
            try {
                $this->client($transport)->chats()->sendMessage(self::CHAT, new ChatMessageRequest('hi', model: 'vendor/model'), 'stable');
                $this->fail('Expected rejection');
            } catch (ApiException $error) {
                $this->assertSame($status, $error->httpStatus);
                $this->assertSame($code, $error->apiError?->code);
                $this->assertCount(1, $transport->requests);
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
