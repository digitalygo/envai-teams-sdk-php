<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Json;

/**
 * Wraps a raw JSON encoded string. The constructor validates the payload so
 * decode() can never fail on a well-formed instance.
 */
final readonly class RawJson implements \JsonSerializable
{
    public function __construct(public string $json)
    {
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Decodes the wrapped payload.
     */
    public function decode(bool $associative = true): mixed
    {
        return json_decode($this->json, $associative, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Serializes as the raw JSON string itself.
     */
    public function jsonSerialize(): string
    {
        return $this->json;
    }
}
