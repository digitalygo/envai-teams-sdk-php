<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Pagination;

use Digitalygo\Envai\Models\Common\ArrayHydratable;

/**
 * A paginated response page in the Laravel paginator wire shape:
 * `{data: [...], links: {first, last, prev, next}, meta: {current_page,
 * from, last_page, per_page, to, total}}`.
 *
 * PHP has no generics, so the item class is carried alongside the items and
 * used by fromArray() to hydrate every entry via ArrayHydratable::fromArray,
 * mirroring ResourceCollection. This is the completion list pagination
 * shape and the pattern for every future paginated endpoint.
 *
 * @template T of ArrayHydratable
 */
final readonly class Page
{
    /**
     * @param  list<T>  $items
     * @param  class-string<T>  $itemClass
     */
    public function __construct(
        public array $items,
        public PageLinks $links,
        public PageMeta $meta,
        public string $itemClass,
    ) {}

    /**
     * @template TItem of ArrayHydratable
     *
     * @param  array<string, mixed>  $data  Laravel paginator shape: data, links, meta.
     * @param  class-string<TItem>  $itemClass
     * @return self<TItem>
     */
    public static function fromArray(array $data, string $itemClass): self
    {
        if (! is_subclass_of($itemClass, ArrayHydratable::class)) {
            throw new \InvalidArgumentException(sprintf('Item class %s must implement %s.', $itemClass, ArrayHydratable::class));
        }

        $context = 'Page';

        if (! array_key_exists('data', $data)) {
            throw new \InvalidArgumentException(sprintf('%s payload is missing required field "data".', $context));
        }

        if (! is_array($data['data'])) {
            throw new \InvalidArgumentException(sprintf('%s payload field "data" must be an array, got %s.', $context, get_debug_type($data['data'])));
        }

        if (! array_key_exists('links', $data) || ! is_array($data['links'])) {
            throw new \InvalidArgumentException(sprintf('%s payload field "links" must be an object.', $context));
        }

        if (! array_key_exists('meta', $data) || ! is_array($data['meta'])) {
            throw new \InvalidArgumentException(sprintf('%s payload field "meta" must be an object.', $context));
        }

        $items = [];

        foreach ($data['data'] as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException(sprintf('%s payload "data" items must be arrays.', $context));
            }

            $items[] = $itemClass::fromArray(self::stringKeyed($item));
        }

        return new self(
            items: $items,
            links: PageLinks::fromArray(self::stringKeyed($data['links'])),
            meta: PageMeta::fromArray(self::stringKeyed($data['meta'])),
            itemClass: $itemClass,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(
                static fn (ArrayHydratable $item): array => self::itemToArray($item),
                $this->items,
            ),
            'links' => $this->links->toArray(),
            'meta' => $this->meta->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function itemToArray(ArrayHydratable $item): array
    {
        if (! method_exists($item, 'toArray')) {
            throw new \InvalidArgumentException(sprintf('Item class %s must expose a toArray() method.', $item::class));
        }

        $result = $item->toArray();

        if (! is_array($result)) {
            throw new \InvalidArgumentException(sprintf('Item class %s toArray() must return an array.', $item::class));
        }

        return self::stringKeyed($result);
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
