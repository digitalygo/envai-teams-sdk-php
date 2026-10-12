<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Security;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Atp\Payload;
use Digitalygo\Envai\Streaming\Events\AgentToolRequestEvent;

/**
 * Verifies the EdDSA signature of a client tool request before the handler
 * runs (BINDING, section 26 of the project document).
 *
 * Rules:
 *
 * - the signature algorithm must be exactly `EdDSA`;
 * - the public key is resolved from the JWKS by `kid` through the
 *   JwksResolver (one forced refresh per unknown kid);
 * - the resolved key must be an Ed25519 signature key (kty OKP, crv Ed25519,
 *   use sig);
 * - the preimage is exactly
 *   `call_id + "\n" + tool_name + "\n" + canonical_json(args)` where the
 *   args are canonicalized per RFC 8785 over the typed JsonObject itself;
 *   the wrapper carries the object/list semantics at depth 0 and the
 *   decoder types every nested value, so a request without arguments
 *   canonicalizes to `{}`, exactly like the gateway signer;
 * - the signature is base64url (padded or unpadded, both accepted) and is
 *   verified with sodium_crypto_sign_verify_detached.
 *
 * Every failure throws ProtocolViolationException with a reason but NEVER
 * includes signature bytes, the preimage or key material in the message.
 * A valid signature proves origin and integrity, not freshness or
 * exactly-once.
 */
final class ToolRequestVerifier
{
    private const ALG_EDDSA = 'EdDSA';

    private const KEY_KTY = 'OKP';

    private const KEY_CRV = 'Ed25519';

    private const KEY_USE = 'sig';

    public function __construct(
        private readonly JwksResolver $resolver,
    ) {}

    /**
     * @throws ProtocolViolationException when the request cannot be verified.
     */
    public function verify(AgentToolRequestEvent $event): VerifiedToolRequest
    {
        $signature = $event->signature;

        if ($signature->alg !== self::ALG_EDDSA) {
            throw self::failure('the signature algorithm must be EdDSA');
        }

        $entry = $this->resolveKey($signature->kid);
        $this->assertUsableKey($entry);

        $preimage = $this->preimage($event);

        $signatureBytes = self::decodeSignature($signature->sig);
        $publicKey = self::decodePublicKey($entry->x);

        if (! sodium_crypto_sign_verify_detached($signatureBytes, $preimage, $publicKey)) {
            throw self::failure('the signature does not verify against the signing key');
        }

        $canonicalArgs = CanonicalJsonSerializer::serialize($event->args);
        $arguments = Payload::typed(json_decode($canonicalArgs, false, 512, JSON_THROW_ON_ERROR));

        if (! $arguments instanceof JsonObject) {
            throw self::failure('verified arguments must remain a JSON object');
        }

        return new VerifiedToolRequest($event->callId, $event->toolName, $arguments, $signature);
    }

    private function resolveKey(string $kid): JwkEntry
    {
        try {
            return $this->resolver->get($kid);
        } catch (\Throwable $exception) {
            $reason = $exception->getMessage() !== '' ? $exception->getMessage() : 'cannot resolve the signing key';

            throw new ProtocolViolationException(
                'client tool signature verification failed: '.$reason,
                [],
                $exception,
            );
        }
    }

    private function assertUsableKey(JwkEntry $entry): void
    {
        if (
            $entry->kty !== self::KEY_KTY
            || $entry->crv !== self::KEY_CRV
            || $entry->use !== self::KEY_USE
            || $entry->alg !== self::ALG_EDDSA
        ) {
            throw self::failure('the resolved signing key is not an Ed25519 signature key');
        }
    }

    private function preimage(AgentToolRequestEvent $event): string
    {
        try {
            $canonicalArgs = CanonicalJsonSerializer::serialize($event->args);
        } catch (\InvalidArgumentException) {
            throw self::failure('the tool arguments cannot be canonicalized');
        }

        return $event->callId."\n".$event->toolName."\n".$canonicalArgs;
    }

    /**
     * @return non-empty-string
     */
    private static function decodeSignature(string $sig): string
    {
        $bytes = self::decodeBase64Url($sig);

        if ($bytes === null) {
            throw self::failure('the signature is not valid base64url');
        }

        if (strlen($bytes) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw self::failure('the signature has an invalid length');
        }

        return $bytes;
    }

    /**
     * @return non-empty-string
     */
    private static function decodePublicKey(string $x): string
    {
        $bytes = self::decodeBase64Url($x);

        if ($bytes === null) {
            throw self::failure('the signing key is not valid base64url');
        }

        if (strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw self::failure('the signing key has an invalid length');
        }

        return $bytes;
    }

    /**
     * Decodes base64url with padding tolerance: both the unpadded form the
     * wire carries and a padded form are accepted and normalized. Returns
     * null when the value is not valid base64.
     */
    private static function decodeBase64Url(string $value): ?string
    {
        $normalized = strtr($value, '-_', '+/');
        $remainder = strlen($normalized) % 4;

        if ($remainder === 1) {
            return null;
        }

        if ($remainder > 0) {
            $normalized .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($normalized, true);

        return $decoded === false ? null : $decoded;
    }

    private static function failure(string $reason): ProtocolViolationException
    {
        return new ProtocolViolationException('client tool signature verification failed: '.$reason);
    }
}
