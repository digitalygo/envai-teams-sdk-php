<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;

final readonly class WebhookPayload implements \JsonSerializable
{
    public WebhookPayloadRootType $rootType;

    public readonly JsonObject|JsonArray $root;

    private ?string $wireJson;

    /** @param array<string|int, mixed>|JsonObject|JsonArray $root */
    public function __construct(JsonObject|JsonArray|array $root, ?string $wireJson = null)
    {
        $this->wireJson = $wireJson;

        if ($root instanceof JsonObject) {
            $this->rootType = WebhookPayloadRootType::Object;
            $this->root = $root;

            return;
        }

        if ($root instanceof JsonArray) {
            $this->rootType = WebhookPayloadRootType::List;
            $this->root = $root;

            return;
        }

        if (array_is_list($root)) {
            $this->rootType = WebhookPayloadRootType::List;
            $this->root = new JsonArray(self::wrapArrayValues($root));

            return;
        }

        $this->rootType = WebhookPayloadRootType::Object;
        $this->root = new JsonObject(self::wrapObjectValues($root));
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        if (! $decoded instanceof \stdClass && ! is_array($decoded)) {
            throw new \InvalidArgumentException('Webhook JSON root must be an object or list.');
        }

        return self::fromDecoded($decoded, $json);
    }

    /** @param \stdClass|array<string|int, mixed> $decoded */
    public static function fromDecoded(\stdClass|array $decoded, ?string $wireJson = null): self
    {
        if ($decoded instanceof \stdClass) {
            return new self(self::wrapObject($decoded), $wireJson);
        }

        return new self(self::wrapArray($decoded), $wireJson);
    }

    /** @param array<string, mixed> $data */
    public static function fromObject(array $data): self
    {
        return new self(new JsonObject(self::wrapObjectValues($data)));
    }

    /** @param array<int, mixed> $data */
    public static function fromList(array $data): self
    {
        if (! array_is_list($data)) {
            throw new \InvalidArgumentException('Webhook list payload data must be a list.');
        }

        return new self(new JsonArray(self::wrapArrayValues($data)));
    }

    public function isObject(): bool
    {
        return $this->rootType === WebhookPayloadRootType::Object;
    }

    public function isList(): bool
    {
        return $this->rootType === WebhookPayloadRootType::List;
    }

    public function object(): JsonObject
    {
        if (! $this->root instanceof JsonObject) {
            throw new \InvalidArgumentException('Webhook payload root is not an object.');
        }

        return $this->root;
    }

    public function list(): JsonArray
    {
        if (! $this->root instanceof JsonArray) {
            throw new \InvalidArgumentException('Webhook payload root is not a list.');
        }

        return $this->root;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (! $this->root instanceof JsonObject) {
            return $default;
        }

        if (! $this->root->has($key)) {
            return $default;
        }

        return $this->root->get($key);
    }

    public function getNative(string $key, mixed $default = null): mixed
    {
        if (! $this->root instanceof JsonObject) {
            return $default;
        }

        if (! $this->root->has($key)) {
            return $default;
        }

        return self::unwrap($this->root->get($key));
    }

    public function has(string $key): bool
    {
        return $this->root instanceof JsonObject && $this->root->has($key);
    }

    /** @return array<string|int, mixed> */
    public function toArray(): array
    {
        if ($this->root instanceof JsonObject) {
            return self::unwrapObject($this->root);
        }

        return self::unwrapArray($this->root);
    }

    public function toJson(): string
    {
        if ($this->wireJson !== null) {
            return $this->wireJson;
        }

        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function withoutWirePayload(): self
    {
        return new self($this->root);
    }

    /** @return \stdClass|array<int|string, mixed>|string|int|float|bool|null */
    public function jsonSerialize(): object|array|string|int|float|bool|null
    {
        return self::jsonValue($this->root);
    }

    private static function wrapObject(\stdClass $value): JsonObject
    {
        return new JsonObject(self::wrapObjectValues(get_object_vars($value)));
    }

    /** @param array<string|int, mixed> $value */
    private static function wrapArray(array $value): JsonArray
    {
        return new JsonArray(self::wrapArrayValues($value));
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @return array<string, mixed>
     */
    private static function wrapObjectValues(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('Webhook object keys must be strings.');
            }

            $result[$key] = self::wrap($item);
        }

        return $result;
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @return list<mixed>
     */
    private static function wrapArrayValues(array $value): array
    {
        $result = [];

        foreach ($value as $item) {
            $result[] = self::wrap($item);
        }

        return $result;
    }

    private static function wrap(mixed $value): JsonObject|JsonArray|string|int|float|bool|null
    {
        if ($value instanceof \stdClass) {
            return self::wrapObject($value);
        }

        if (is_array($value)) {
            return self::wrapArray($value);
        }

        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('Webhook JSON contains an unsupported value.');
    }

    /** @return \stdClass|array<int|string, mixed>|string|int|float|bool|null */
    private static function jsonValue(mixed $value): object|array|string|int|float|bool|null
    {
        if ($value instanceof JsonObject) {
            $object = new \stdClass;

            foreach ($value->toArray() as $key => $item) {
                $object->{$key} = self::jsonValue($item);
            }

            return $object;
        }

        if ($value instanceof JsonArray) {
            $items = [];

            foreach ($value->toArray() as $item) {
                $items[] = self::jsonValue($item);
            }

            return $items;
        }

        if (is_array($value)) {
            $items = [];

            foreach ($value as $key => $item) {
                $items[$key] = self::jsonValue($item);
            }

            return $items;
        }

        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        throw new \InvalidArgumentException('Webhook JSON contains an unsupported value.');
    }

    private static function unwrap(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            return self::unwrapObject($value);
        }

        if ($value instanceof JsonArray) {
            return self::unwrapArray($value);
        }

        if (is_array($value)) {
            $result = [];

            foreach ($value as $key => $item) {
                $result[$key] = self::unwrap($item);
            }

            return $result;
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private static function unwrapObject(JsonObject $value): array
    {
        $result = [];

        foreach ($value->toArray() as $key => $item) {
            $result[$key] = self::unwrap($item);
        }

        return $result;
    }

    /** @return list<mixed> */
    private static function unwrapArray(JsonArray $value): array
    {
        $result = [];

        foreach ($value->toArray() as $item) {
            $result[] = self::unwrap($item);
        }

        return $result;
    }
}
