<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\Actions;

use Digitalygo\Envai\Webhooks\WebhookManager;
use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\WebhookRequestMetadata;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class ReceiveEnvaiWebhook
{
    public function __construct(private Container $container) {}

    public function __invoke(Request $request): Response
    {
        $manager = $this->container->make('envai.webhooks.manager');

        if (! $manager instanceof WebhookManager) {
            throw new \LogicException('The container binding "envai.webhooks.manager" must resolve to a WebhookManager.');
        }

        $result = $manager->process($this->webhookRequest($request));
        $response = $manager->responseFor($result);

        if (! $response instanceof Response) {
            throw new \LogicException('The Envai webhook response policy must return an Illuminate\\Http\\Response.');
        }

        return $response;
    }

    private function webhookRequest(Request $request): WebhookRequest
    {
        $rawBody = $request->getContent();

        if (! is_string($rawBody)) {
            throw new \LogicException('The Laravel webhook request body must be available as a string.');
        }

        return new WebhookRequest(
            method: $request->getMethod(),
            uri: $request->getRequestUri(),
            headers: $request->headers->all(),
            rawBody: $rawBody,
            metadata: new WebhookRequestMetadata(
                remoteAddress: $this->serverString($request, 'REMOTE_ADDR'),
                serverProtocol: $this->serverString($request, 'SERVER_PROTOCOL'),
                serverName: $this->serverString($request, 'SERVER_NAME'),
                serverPort: $this->serverPort($request),
            ),
        );
    }

    private function serverString(Request $request, string $key): ?string
    {
        $value = $request->server($key);

        return is_string($value) ? $value : null;
    }

    private function serverPort(Request $request): ?int
    {
        $value = $request->server('SERVER_PORT');

        if (is_int($value)) {
            return $value >= 1 && $value <= 65535 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            return null;
        }

        $normalized = ltrim($value, '0');
        $normalized = $normalized === '' ? '0' : $normalized;

        if (strlen($normalized) > 5 || (strlen($normalized) === 5 && strcmp($normalized, '65535') > 0)) {
            return null;
        }

        $port = (int) $normalized;

        return $port >= 1 ? $port : null;
    }
}
