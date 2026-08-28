<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Support;

/**
 * Typed access to a decoded JSON object tool output payload.
 */
final class ToolOutputPayload
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        public readonly array $data,
    ) {}

    /**
     * Decodes a JSON object payload.
     */
    public static function decode(string $json): self
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Expected a JSON object payload.');
        }

        /** @var array<string, mixed> $decoded */
        return new self($decoded);
    }

    public function string(string $key): string
    {
        $value = $this->data[$key] ?? null;

        if (! is_string($value)) {
            throw new \RuntimeException(sprintf('Payload field "%s" is not a string.', $key));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new \RuntimeException(sprintf('Payload field "%s" is not a list.', $key));
        }

        /** @var list<string> $value */
        return $value;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
}
