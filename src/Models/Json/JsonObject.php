<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Json;

/**
 * Wraps an associative array preserving key order, mirroring a JSON object.
 */
final readonly class JsonObject implements \JsonSerializable
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(public array $data) {}

    public static function fromJson(string $json): self
    {
        $trimmed = trim($json);

        if ($trimmed === '' || $trimmed[0] !== '{') {
            throw new \InvalidArgumentException('Expected a JSON object literal.');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new \InvalidArgumentException('Expected a JSON object literal.');
        }

        return new self($decoded);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    public function jsonSerialize(): \stdClass
    {
        return (object) $this->data;
    }
}
