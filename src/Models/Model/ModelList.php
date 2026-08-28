<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Model;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Top-level model catalog response without a data wrapper: `object` is
 * always `list`, `data` mixes LLM entries and team alias entries and
 * `defaults` carries the platform default model id per role.
 *
 * Every entry is hydrated by its `type` discriminator: `llm` builds an
 * LlmModelEntry and `team` a TeamModelEntry. An unknown discriminator is a
 * malformed payload and fails loudly instead of guessing.
 */
final readonly class ModelList implements ArrayHydratable
{
    /**
     * @param  list<ModelListEntry>  $data
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $object,
        public array $data,
        public ModelDefaults $defaults,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'ModelList';
        $rawEntries = Wire::presentOrFail($data, 'data', $context);

        if (! is_array($rawEntries)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "data" must be an array, got %s.',
                $context,
                get_debug_type($rawEntries),
            ));
        }

        $entries = [];

        foreach ($rawEntries as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "data" must be a list of catalog entries, got a non-array entry.',
                    $context,
                ));
            }

            $entries[] = self::entry(Wire::stringKeyed($item, 'data', $context));
        }

        return new self(
            object: Wire::string($data, 'object', $context),
            data: $entries,
            defaults: ModelDefaults::fromArray(Wire::objectArray($data, 'defaults', $context)),
            extra: Wire::extra($data, ['object', 'data', 'defaults']),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function entry(array $item): ModelListEntry
    {
        $type = $item['type'] ?? null;

        if ($type === 'llm') {
            return LlmModelEntry::fromArray($item);
        }

        if ($type === 'team') {
            return TeamModelEntry::fromArray($item);
        }

        throw new \InvalidArgumentException(sprintf(
            'ModelList payload entry has unknown discriminator "type" value %s; expected "llm" or "team".',
            get_debug_type($type),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'object' => $this->object,
            'data' => array_map(static fn (ModelListEntry $entry): array => self::entryToArray($entry), $this->data),
            'defaults' => $this->defaults->toArray(),
        ], $this->extra);
    }

    /**
     * @return array<string, mixed>
     */
    private static function entryToArray(ModelListEntry $entry): array
    {
        if ($entry instanceof LlmModelEntry) {
            return $entry->toArray();
        }

        if ($entry instanceof TeamModelEntry) {
            return $entry->toArray();
        }

        throw new \InvalidArgumentException(sprintf('Unsupported model catalog entry class %s.', $entry::class));
    }
}
