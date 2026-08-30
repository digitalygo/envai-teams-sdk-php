<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Integration\Laravel\Actions\ReceiveEnvaiWebhook;
use Digitalygo\Envai\Webhooks\Contracts\WebhookDispatcher;
use Digitalygo\Envai\Webhooks\Contracts\WebhookResponsePolicy;
use Digitalygo\Envai\Webhooks\Contracts\WebhookVerifier;
use Digitalygo\Envai\Webhooks\InMemoryWebhookIdempotencyStore;
use Digitalygo\Envai\Webhooks\WebhookManager;
use Digitalygo\Envai\Webhooks\WebhookPayload;
use Digitalygo\Envai\Webhooks\WebhookProcessingResult;
use Digitalygo\Envai\Webhooks\WebhookReceived;
use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\WebhookValidator;
use Digitalygo\Envai\Webhooks\WebhookVerificationResult;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class ReceiveEnvaiWebhookTest extends IntegrationTestCase
{
    public function test_returns_the_response_from_the_configured_policy_and_converts_the_http_request(): void
    {
        $verifier = new ReceiveEnvaiWebhookVerifier;
        $response = new Response('accepted', 202);
        $container = new Container;
        $container->instance('envai.webhooks.manager', $this->manager($verifier, new ReceiveEnvaiWebhookPolicy($response)));
        $body = '{ "event" : "created", "nested": { "empty": {} } }';
        $request = Request::create(
            '/webhooks?z=last&a=first',
            'POST',
            server: [
                'REMOTE_ADDR' => '192.0.2.10',
                'SERVER_PROTOCOL' => 'HTTP/2',
                'SERVER_NAME' => 'hooks.example.test',
                'SERVER_PORT' => '8443',
            ],
            content: $body,
        );
        $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('X-Multi', ['first', 'second']);

        $actual = (new ReceiveEnvaiWebhook($container))($request);
        $converted = $verifier->request;

        $this->assertSame($response, $actual);
        $this->assertNotNull($converted);
        $this->assertSame('POST', $converted->method);
        $this->assertSame('/webhooks?z=last&a=first', $converted->uri);
        $this->assertSame(['first', 'second'], $converted->headerValues('x-multi'));
        $this->assertSame(['application/json'], $converted->headerValues('content-type'));
        $this->assertSame($body, $converted->rawBody);
        $this->assertSame('192.0.2.10', $converted->metadata->remoteAddress);
        $this->assertSame('HTTP/2', $converted->metadata->serverProtocol);
        $this->assertSame('hooks.example.test', $converted->metadata->serverName);
        $this->assertSame(8443, $converted->metadata->serverPort);
    }

    public function test_fails_closed_when_the_response_policy_returns_a_non_response_object(): void
    {
        $container = new Container;
        $container->instance('envai.webhooks.manager', $this->manager(
            new ReceiveEnvaiWebhookVerifier,
            new ReceiveEnvaiWebhookPolicy(new \stdClass),
        ));
        $request = Request::create('/webhooks', 'POST', content: '{}');
        $request->headers->set('Content-Type', 'application/json');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('response policy must return an Illuminate\\Http\\Response');

        (new ReceiveEnvaiWebhook($container))($request);
    }

    private function manager(ReceiveEnvaiWebhookVerifier $verifier, WebhookResponsePolicy $policy): WebhookManager
    {
        return new WebhookManager(
            new WebhookValidator,
            $verifier,
            new InMemoryWebhookIdempotencyStore,
            new ReceiveEnvaiWebhookDispatcher,
            $policy,
            'webhook-scope',
        );
    }
}

final class ReceiveEnvaiWebhookVerifier implements WebhookVerifier
{
    public ?WebhookRequest $request = null;

    public function verify(WebhookRequest $request, WebhookPayload $payload): WebhookVerificationResult
    {
        $this->request = $request;

        return new WebhookVerificationResult('evt_action', 'created');
    }
}

final class ReceiveEnvaiWebhookDispatcher implements WebhookDispatcher
{
    public function dispatch(WebhookReceived $event): void {}
}

final class ReceiveEnvaiWebhookPolicy implements WebhookResponsePolicy
{
    public function __construct(private object $response) {}

    public function responseFor(WebhookProcessingResult $result): object
    {
        return $this->response;
    }
}
