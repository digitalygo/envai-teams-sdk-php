<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Security;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;

/**
 * One Ed25519 public signing key entry of the control plane JWKS document
 * used to verify client tool signatures.
 *
 * Values are plain strings instead of PHP enums so a future algorithm
 * addition still hydrates; the shape expected for client tool signatures is
 * kty OKP, crv Ed25519, use sig and alg EdDSA. `kid` is the signing key id
 * (`sig_<ulid>`) and retired keys stay published.
 */
final readonly class JwkEntry
{
    /**
     * @param  array<string, mixed>  $extra  Unknown key entry fields preserved for forward compatibility.
     */
    public function __construct(
        public string $kty,
        public string $crv,
        public string $x,
        public string $kid,
        public string $use,
        public string $alg,
        public array $extra = [],
    ) {}

    /**
     * Hydrates a key entry from a decoded JWKS document member. Missing or
     * non-string required fields are protocol violations: a JWKS entry
     * without the full signing metadata cannot be used to verify anything.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            kty: self::requiredString($data, 'kty'),
            crv: self::requiredString($data, 'crv'),
            x: self::requiredString($data, 'x'),
            kid: self::requiredString($data, 'kid'),
            use: self::requiredString($data, 'use'),
            alg: self::requiredString($data, 'alg'),
            extra: array_diff_key($data, array_flip(['kty', 'crv', 'x', 'kid', 'use', 'alg'])),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw new ProtocolViolationException(sprintf(
                'Control plane JWKS key entry field "%s" must be a non-empty string.',
                $field,
            ));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'kty' => $this->kty,
            'crv' => $this->crv,
            'x' => $this->x,
            'kid' => $this->kid,
            'use' => $this->use,
            'alg' => $this->alg,
        ], $this->extra);
    }
}
