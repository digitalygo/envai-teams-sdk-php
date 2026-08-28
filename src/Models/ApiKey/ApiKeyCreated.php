<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ApiKey;

use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Creation response of an api key: the full metadata plus the plaintext
 * token `envai_sk_<ulid><jwt>`, shown exactly once and never persisted.
 *
 * `token` is a SensitiveString: casting to string redacts it, logging and
 * dumping never embed the secret, and the value is only reachable through an
 * explicit ->token->reveal() call. toArray() deliberately omits the token so
 * the plaintext cannot leak through casual serialization; the key metadata
 * and any unknown keys are preserved.
 */
final readonly class ApiKeyCreated implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public ApiKey $key,
        public SensitiveString $token,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'ApiKeyCreated';

        if (! array_key_exists('token', $data)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload is missing required field "token".',
                $context,
            ));
        }

        if (! is_string($data['token'])) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "token" must be a string, got %s.',
                $context,
                get_debug_type($data['token']),
            ));
        }

        $metadata = $data;
        unset($metadata['token']);

        return new self(
            key: ApiKey::fromArray($metadata),
            token: new SensitiveString($data['token']),
            extra: Wire::extra($metadata, ['id', 'name', 'scopes', 'expires_at', 'revoked_at', 'created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge($this->key->toArray(), $this->extra);
    }
}
