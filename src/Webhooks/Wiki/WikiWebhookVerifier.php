<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks\Wiki;

use Digitalygo\Envai\Tools\Security\JwksResolver;
use Digitalygo\Envai\Webhooks\Contracts\WebhookVerifier;
use Digitalygo\Envai\Webhooks\WebhookPayload;
use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\WebhookVerificationException;
use Digitalygo\Envai\Webhooks\WebhookVerificationMetadata;
use Digitalygo\Envai\Webhooks\WebhookVerificationResult;

final readonly class WikiWebhookVerifier implements WebhookVerifier
{
    /** @param \Closure(): int|null $clock */
    public function __construct(private JwksResolver $keys, private string $workspaceId, private int $toleranceSeconds = 300, private ?\Closure $clock = null)
    {
        if ($workspaceId === '' || $toleranceSeconds < 1) {
            throw new \InvalidArgumentException('Expected workspace and positive timestamp tolerance are required.');
        }
    }

    public function verify(WebhookRequest $request, WebhookPayload $payload): WebhookVerificationResult
    {
        if (strtoupper($request->method) !== 'POST') {
            throw new WebhookVerificationException('Wiki webhooks require POST.');
        }
        $id = self::singleHeader($request, 'envai-webhook-id');
        $kid = self::singleHeader($request, 'envai-webhook-key-id');
        $timestamp = self::singleHeader($request, 'envai-webhook-timestamp');
        $encoded = self::singleHeader($request, 'envai-webhook-signature');
        if (preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $id) !== 1 || preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $kid) !== 1 || preg_match('/^[1-9][0-9]{0,10}$/D', $timestamp) !== 1) {
            throw new WebhookVerificationException('Malformed webhook signing headers.');
        }
        $now = $this->clock === null ? time() : ($this->clock)();
        if (abs($now - (int) $timestamp) > $this->toleranceSeconds) {
            throw new WebhookVerificationException('Webhook delivery timestamp is outside the replay window.');
        }
        $signature = self::decode($encoded, SODIUM_CRYPTO_SIGN_BYTES);
        $key = $this->keys->get($kid);
        if ($key->kty !== 'OKP' || $key->crv !== 'Ed25519' || $key->alg !== 'EdDSA' || $key->use !== 'sig') {
            throw new WebhookVerificationException('Webhook key is not an Ed25519 signing key.');
        }
        $publicKey = self::decode($key->x, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        $input = self::signingInput($kid, $id, $timestamp, $request->rawBody);
        if (! sodium_crypto_sign_verify_detached($signature, $input, $publicKey)) {
            throw new WebhookVerificationException('Wiki webhook signature is invalid.');
        }
        $event = WikiWebhookEvent::fromPayload(WebhookPayload::fromJson($request->rawBody));
        if (! hash_equals($id, $event->eventId) || ! hash_equals($this->workspaceId, $event->workspaceId)) {
            throw new WebhookVerificationException('Wiki webhook identity or workspace does not match the receiver.');
        }

        return new WebhookVerificationResult($event->eventId, $event->eventType, $timestamp, metadata: WebhookVerificationMetadata::fromAllowlist(['workspace_id' => $event->workspaceId, 'operation_id' => $event->operationId, 'key_id' => $kid], ['workspace_id', 'operation_id', 'key_id']));
    }

    public static function signingInput(string $kid, string $eventId, string $timestamp, string $rawBody): string
    {
        return "envai-webhook-v1\n".$kid."\n".$eventId."\n".$timestamp."\n".hash('sha256', $rawBody);
    }

    private static function singleHeader(WebhookRequest $request, string $name): string
    {
        $values = $request->headerValues($name);
        if (count($values) !== 1 || $values[0] === '') {
            throw new WebhookVerificationException('Missing or repeated webhook signing header.');
        }

        return $values[0];
    }

    /** @return non-empty-string */
    private static function decode(string $value, int $bytes): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new WebhookVerificationException('Malformed base64url webhook value.');
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false || $decoded === '' || strlen($decoded) !== $bytes || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $value) {
            throw new WebhookVerificationException('Malformed base64url webhook value.');
        }

        return $decoded;
    }
}
