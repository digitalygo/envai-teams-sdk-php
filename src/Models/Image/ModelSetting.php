<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Model setting exposed by one or more providers. The setting applies to the
 * providers listed in `providers` only; `default` is a free value,
 * `enum`/`range` constrain the allowed values and `defaults_by_provider`
 * carries per provider defaults when they differ (wire-faithful JsonObject).
 */
final readonly class ModelSetting implements ArrayHydratable
{
    /**
     * @param  list<string>  $providers
     * @param  list<string>|null  $enum
     * @param  list<int>|null  $range
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $key,
        public string $type,
        public array $providers,
        public mixed $default = null,
        public ?array $enum = null,
        public ?array $range = null,
        public ?JsonObject $defaultsByProvider = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'ModelSetting';

        return new self(
            key: Wire::string($data, 'key', $context),
            type: Wire::string($data, 'type', $context),
            providers: Wire::stringList($data, 'providers', $context),
            default: $data['default'] ?? null,
            enum: self::nullableStringList($data, 'enum', $context),
            range: self::nullableIntList($data, 'range', $context),
            defaultsByProvider: Wire::nullableJsonObject($data, 'defaults_by_provider', $context),
            extra: Wire::extra($data, ['key', 'type', 'providers', 'default', 'enum', 'range', 'defaults_by_provider']),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     */
    private static function nullableStringList(array $data, string $key, string $context): ?array
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return Wire::stringList($data, $key, $context);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>|null
     */
    private static function nullableIntList(array $data, string $key, string $context): ?array
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        $value = Wire::presentOrFail($data, $key, $context);

        if (! is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" must be an array, got %s.',
                $context,
                $key,
                get_debug_type($value),
            ));
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_int($item)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "%s" must be a list of integers, got a non-integer entry.',
                    $context,
                    $key,
                ));
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'key' => $this->key,
            'type' => $this->type,
            'providers' => $this->providers,
        ];

        if ($this->default !== null) {
            $payload['default'] = $this->default;
        }

        if ($this->enum !== null) {
            $payload['enum'] = $this->enum;
        }

        if ($this->range !== null) {
            $payload['range'] = $this->range;
        }

        if ($this->defaultsByProvider !== null) {
            $payload['defaults_by_provider'] = $this->defaultsByProvider->toArray();
        }

        return array_merge($payload, $this->extra);
    }
}
