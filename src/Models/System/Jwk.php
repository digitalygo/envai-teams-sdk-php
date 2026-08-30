<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\System;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * One Ed25519 public signing key entry of the JWKS document.
 *
 * Values are kept as plain strings instead of PHP enums so a future
 * algorithm addition still hydrates; `kid` is the signing key id
 * `sig_<ulid>` and retired keys stay published.
 */
final readonly class Jwk implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
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
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'Jwk';

        return new self(
            kty: Wire::string($data, 'kty', $context),
            crv: Wire::string($data, 'crv', $context),
            x: Wire::string($data, 'x', $context),
            kid: Wire::string($data, 'kid', $context),
            use: Wire::string($data, 'use', $context),
            alg: Wire::string($data, 'alg', $context),
            extra: Wire::extra($data, ['kty', 'crv', 'x', 'kid', 'use', 'alg']),
        );
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
