<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Json;

/**
 * Wraps a JSON array literal as a PHP list.
 */
final readonly class JsonArray implements \JsonSerializable
{
    /**
     * @param  list<mixed>  $data
     */
    public function __construct(public array $data) {}

    public static function fromJson(string $json): self
    {
        $trimmed = trim($json);

        if ($trimmed === '' || $trimmed[0] !== '[') {
            throw new \InvalidArgumentException('Expected a JSON array literal.');
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new \InvalidArgumentException('Expected a JSON array literal.');
        }

        return new self($decoded);
    }

    /**
     * @return list<mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    public function jsonSerialize(): mixed
    {
        return $this->data;
    }
}
