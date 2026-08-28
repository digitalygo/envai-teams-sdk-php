<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Support;

use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Ed25519 key and JWKS helpers for the client tool runtime tests.
 *
 * Keys are generated at test time with ext-sodium; preimages are signed
 * with sodium_crypto_sign_detached and encoded as unpadded base64url,
 * mirroring the wire format of `agent.tool.request`.
 */
final class TestKeys
{
    /**
     * Generates an Ed25519 keypair.
     *
     * @return array{secret: non-empty-string, public: non-empty-string}
     */
    public static function generate(): array
    {
        $keypair = sodium_crypto_sign_keypair();

        return [
            'secret' => sodium_crypto_sign_secretkey($keypair),
            'public' => sodium_crypto_sign_publickey($keypair),
        ];
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function kid(int $suffix = 1): string
    {
        return 'sig_test_'.$suffix;
    }

    /**
     * @return array<string, mixed>
     */
    public static function jwkEntry(string $kid, string $publicKey): array
    {
        return [
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => self::b64url($publicKey),
            'kid' => $kid,
            'use' => 'sig',
            'alg' => 'EdDSA',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function jwkDocument(string $kid, string $publicKey): array
    {
        return ['keys' => [self::jwkEntry($kid, $publicKey)]];
    }

    /**
     * Signs the canonical preimage `call_id + "\n" + tool_name + "\n" +
     * canonical_json(args)` with the given secret key.
     *
     * Args are canonicalized through the JsonObject wrapper so an empty
     * argument list signs over `{}`, exactly like the gateway signer
     * (which falls back to `{}` when args are missing or cannot be
     * canonicalized).
     *
     * @param  array<string, mixed>  $args
     * @param  non-empty-string  $secretKey
     */
    public static function sign(string $callId, string $toolName, array $args, string $secretKey): string
    {
        $preimage = $callId."\n".$toolName."\n".CanonicalJsonSerializer::serialize(new JsonObject($args));

        return self::b64url(sodium_crypto_sign_detached($preimage, $secretKey));
    }

    /**
     * Signs an explicit canonical JSON payload (used to prove RFC 8785
     * compliance against hand-computed forms).
     *
     * @param  non-empty-string  $secretKey
     */
    public static function signPayload(string $preimage, string $secretKey): string
    {
        return self::b64url(sodium_crypto_sign_detached($preimage, $secretKey));
    }
}
