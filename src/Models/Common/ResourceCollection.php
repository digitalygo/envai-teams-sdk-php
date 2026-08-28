<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Common;

/**
 * A generic collection of hydrated DTOs.
 *
 * PHP has no generics, so the item class is carried alongside the items and
 * used by the fromArray factory to hydrate every entry via
 * ArrayHydratable::fromArray().
 *
 * @template T of ArrayHydratable
 */
final readonly class ResourceCollection
{
    /**
     * @param  list<T>  $items
     * @param  class-string<T>  $itemClass
     */
    public function __construct(
        public array $items,
        public string $itemClass,
    ) {}

    /**
     * @template TItem of ArrayHydratable
     *
     * @param  list<mixed>  $data
     * @param  class-string<TItem>  $itemClass
     * @return self<TItem>
     */
    public static function fromArray(array $data, string $itemClass): self
    {
        if (! is_subclass_of($itemClass, ArrayHydratable::class)) {
            throw new \InvalidArgumentException(sprintf('Item class %s must implement %s.', $itemClass, ArrayHydratable::class));
        }

        $items = [];

        foreach ($data as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException('Resource collection items must be arrays.');
            }

            $items[] = $itemClass::fromArray(self::stringKeyed($item));
        }

        return new self($items, $itemClass);
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
