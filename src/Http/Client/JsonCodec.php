<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Client;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Http\MultipartBody;
use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Common\ResourceCollection;
use Digitalygo\Envai\Models\Json\CanonicalJson;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Models\Json\RawJson;
use Digitalygo\Envai\Models\Tool\UserTool;
use Digitalygo\Envai\Tools\Schema\ToolSchemaNormalizer;

/**
 * Encodes request bodies and decodes response bodies into typed DTOs.
 *
 * Decoding contract: unknown fields in a payload are passed through to
 * DTO::fromArray() unchanged and the models package is responsible for
 * preserving them in the DTO $extra map. Malformed JSON, non-object payloads
 * and missing envelope wrappers are surfaced as ProtocolViolationException,
 * never as a generic decoding failure.
 */
final class JsonCodec
{
    private const ENCODE_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Encodes a request body into its JSON representation.
     *
     * null stays null, strings are passed through as-is (already encoded
     * JSON), JsonObject/JsonArray retain their explicit JSON shapes and any
     * other JSON-encodable value is encoded with unescaped slashes and
     * unicode. RawJson payloads are passed through verbatim. Multipart bodies
     * are rejected: they must be sent through send() directly.
     *
     * @throws \JsonException when the value is not JSON-encodable.
     */
    public function encodeRequestBody(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }

        if (is_string($body)) {
            return $body;
        }

        if ($body instanceof JsonObject || $body instanceof JsonArray) {
            return $this->encode($body);
        }

        if ($body instanceof CanonicalJson) {
            return $this->encode($body->value());
        }

        if ($body instanceof RawJson) {
            return $body->json;
        }

        if ($body instanceof MultipartBody) {
            throw new \InvalidArgumentException('Multipart bodies cannot be JSON encoded; use send() directly.');
        }

        return $this->encode($body);
    }

    /**
     * Decodes an unwrapped top-level JSON object into the given DTO.
     *
     * This is the generic primitive: no {data} unwrapping happens here, even
     * when the payload is a single-resource envelope. Use
     * decodeEnvelopeInto() or decodeCollectionInto() for wrapped payloads.
     *
     * @param  class-string<ArrayHydratable>  $class
     */
    public function decodeTopLevelInto(string $body, string $class): object
    {
        return $this->decodeInto($body, $class);
    }

    /**
     * Decodes a single-resource {data: {...}} envelope into the given DTO.
     *
     * @param  class-string  $class  Must implement ArrayHydratable.
     *
     * @throws ProtocolViolationException when the wrapper is missing or the
     *                                    data member is not a JSON object.
     */
    public function decodeEnvelopeInto(string $body, string $class): object
    {
        self::assertHydratable($class);

        $envelope = self::decodeObject($body, $class === UserTool::class);

        $payload = $envelope['data'] ?? null;

        if (! is_array($payload) || ! self::isJsonObject($payload)) {
            throw new ProtocolViolationException('Expected a single-resource {data: {...}} response envelope, but the "data" member is missing or not an object.');
        }

        return $class::fromArray(self::stringKeyed($payload));
    }

    /**
     * Decodes a paginated {data: [...]} envelope into a ResourceCollection.
     *
     * @template T of ArrayHydratable
     *
     * @param  class-string<T>  $class
     * @return ResourceCollection<T>
     *
     * @throws ProtocolViolationException when the wrapper is missing or the
     *                                    data member is not a JSON array.
     */
    public function decodeCollectionInto(string $body, string $class): ResourceCollection
    {
        $envelope = self::decodeObject($body, $class === UserTool::class);

        $payload = $envelope['data'] ?? null;

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw new ProtocolViolationException('Expected a collection {data: [...]} response envelope, but the "data" member is missing or not an array.');
        }

        return ResourceCollection::fromArray($payload, $class);
    }

    /**
     * Decodes a JSON object payload into the given DTO without unwrapping.
     *
     * @param  class-string  $class  Must implement ArrayHydratable.
     *
     * @throws ProtocolViolationException when the payload is malformed or not
     *                                    a JSON object.
     */
    public function decodeInto(string $body, string $class): object
    {
        self::assertHydratable($class);

        return $class::fromArray(self::decodeObject($body, $class === UserTool::class));
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS);
    }

    /**
     * Decodes a JSON object literal into an associative array.
     *
     * @return array<string, mixed>
     *
     * @throws ProtocolViolationException when the body is malformed JSON or
     *                                    not a JSON object.
     */
    private static function decodeObject(string $body, bool $preserveToolSchema = false): array
    {
        try {
            $decoded = json_decode($body, ! $preserveToolSchema, 512, JSON_THROW_ON_ERROR);
            if ($preserveToolSchema && $decoded instanceof \stdClass) {
                $decoded = self::toolResponseValue($decoded);
            }
        } catch (\JsonException $e) {
            throw new ProtocolViolationException('Malformed JSON in the response body.', previous: $e);
        }

        if (! is_array($decoded) || ! self::isJsonObject($decoded)) {
            throw new ProtocolViolationException('Expected a JSON object response body.');
        }

        return self::stringKeyed($decoded);
    }

    /** Preserve only tool schema subtrees; other DTO fields keep their array API. */
    private static function toolResponseValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $result = [];
            foreach (get_object_vars($value) as $key => $item) {
                $result[$key] = $key === 'parameters' && $item instanceof \stdClass
                    ? ToolSchemaNormalizer::objectPayload($item)
                    : self::toolResponseValue($item);
            }

            return $result;
        }

        return is_array($value) ? array_map(self::toolResponseValue(...), $value) : $value;
    }

    /**
     * Distinguishes a JSON object (possibly empty) from a JSON array.
     *
     * An empty PHP array is both an empty list and an empty object literal,
     * so only non-empty lists are classified as arrays.
     *
     * @param  array<mixed, mixed>  $data
     */
    private static function isJsonObject(array $data): bool
    {
        return $data === [] || ! array_is_list($data);
    }

    /**
     * @param  class-string  $class
     *
     * @phpstan-assert class-string<ArrayHydratable> $class
     */
    private static function assertHydratable(string $class): void
    {
        if (! is_subclass_of($class, ArrayHydratable::class)) {
            throw new \InvalidArgumentException(sprintf('%s must implement %s.', $class, ArrayHydratable::class));
        }
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
