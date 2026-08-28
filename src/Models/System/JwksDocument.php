<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\System;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * JSON Web Key Set of the control plane signing keys, served at
 * /.well-known/jwks.json.
 */
final readonly class JwksDocument implements ArrayHydratable
{
    /**
     * @param  list<Jwk>  $keys
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public array $keys,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'JwksDocument';
        $rawKeys = Wire::presentOrFail($data, 'keys', $context);

        if (! is_array($rawKeys)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "keys" must be an array, got %s.',
                $context,
                get_debug_type($rawKeys),
            ));
        }

        $keys = [];

        foreach ($rawKeys as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "keys" must be a list of key objects, got a non-array entry.',
                    $context,
                ));
            }

            $keys[] = Jwk::fromArray(Wire::stringKeyed($item, 'keys', $context));
        }

        return new self(
            keys: $keys,
            extra: Wire::extra($data, ['keys']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'keys' => array_map(static fn (Jwk $key): array => $key->toArray(), $this->keys),
        ], $this->extra);
    }
}
