<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Security;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;

/**
 * JSON Web Key Set of the control plane signing keys, served at
 * /.well-known/jwks.json and used to verify client tool signatures.
 *
 * This is the tool-security counterpart of the Models\System\JwksDocument
 * HTTP model: it hydrates from raw JSON fetched by the JwksResolver and
 * exposes key lookup by `kid`. A malformed document is a protocol
 * violation: signature material cannot be verified against it.
 */
final readonly class JwksDocument
{
    /**
     * @param  list<JwkEntry>  $keys
     */
    public function __construct(
        public array $keys,
    ) {}

    /**
     * Parses a JWKS document from its raw JSON body.
     */
    public static function fromJson(string $json): self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ProtocolViolationException('Control plane JWKS document is not valid JSON.');
        }

        if (! is_array($decoded)) {
            throw new ProtocolViolationException('Control plane JWKS document must be a JSON object.');
        }

        return self::fromArray(self::stringKeyed($decoded));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawKeys = $data['keys'] ?? null;

        if (! is_array($rawKeys) || ! array_is_list($rawKeys)) {
            throw new ProtocolViolationException('Control plane JWKS document field "keys" must be a list of key objects.');
        }

        $keys = [];

        foreach ($rawKeys as $item) {
            if (! is_array($item)) {
                throw new ProtocolViolationException('Control plane JWKS document field "keys" must be a list of key objects.');
            }

            $keys[] = JwkEntry::fromArray(self::stringKeyed($item));
        }

        return new self($keys);
    }

    /**
     * Returns the key entry for the given kid, or null when absent.
     */
    public function get(string $kid): ?JwkEntry
    {
        foreach ($this->keys as $key) {
            if ($key->kid === $kid) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'keys' => array_map(static fn (JwkEntry $key): array => $key->toArray(), $this->keys),
        ];
    }

    /**
     * Keeps only string keys, matching the shape json_decode produces for a
     * JSON object.
     *
     * @param  array<mixed, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
