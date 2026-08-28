<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Diff;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Recursive key-wise difference between two normalized payloads.
 *
 * between() walks both sides in parallel: object members are matched by
 * key (order-insensitive, as JSON objects are unordered), list items by
 * index (order-sensitive). Only leaf differences produce entries;
 * containers are recursed into and their path uses dot notation for
 * object keys and `[i]` for list indices.
 *
 * The object/list distinction of an empty PHP array is ambiguous, so it
 * is honored only when the explicit JsonObject/JsonArray wrappers are
 * present: an empty plain array compares equal to an empty object or an
 * empty list on the other side. Scalars compare strictly, except that
 * int and float values are compared numerically, which keeps JSON
 * numbers 1 and 1.0 equal.
 */
final readonly class DefinitionDiff
{
    /**
     * @param  list<DiffEntry>  $entries
     */
    public function __construct(public array $entries = []) {}

    /**
     * Computes the difference describing how to go from $lastApplied to
     * $desired: entries with kind Added appear in desired only, Removed
     * in lastApplied only, Changed in both with a different leaf value.
     *
     * @param  array<string, mixed>  $desired
     * @param  array<string, mixed>  $lastApplied
     */
    public static function between(array $desired, array $lastApplied): self
    {
        $entries = [];

        self::walk($lastApplied, $desired, '', $entries);

        return new self($entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /**
     * Renders the diff as human-readable lines:
     *
     *     + description: "Query the database"
     *     ~ timeout_seconds: 30 -> 60
     *     - tools_enabled[0].name: "old" -> removed
     *
     * Values are JSON-encoded; strings keep their quotes, so the output
     * is unambiguous.
     */
    public function render(): string
    {
        $lines = [];

        foreach ($this->entries as $entry) {
            $lines[] = match ($entry->kind) {
                DiffKind::Added => '+ '.$entry->path.': '.self::formatValue($entry->new),
                DiffKind::Removed => '- '.$entry->path.': '.self::formatValue($entry->old),
                DiffKind::Changed => '~ '.$entry->path.': '.self::formatValue($entry->old).' -> '.self::formatValue($entry->new),
            };
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<DiffEntry>  $entries
     */
    private static function walk(mixed $old, mixed $new, string $path, array &$entries): void
    {
        $oldShape = self::shape($old);
        $newShape = self::shape($new);

        if ($oldShape === 'scalar' || $newShape === 'scalar') {
            if (self::equalScalars($old, $new)) {
                return;
            }

            $entries[] = new DiffEntry($path, DiffKind::Changed, $old, $new);

            return;
        }

        // An empty plain array is shape-ambiguous; align it to the other
        // side so `[]` matches both `{}` and `[]` unless a wrapper pins
        // the shape on one side.
        $oldShape = self::resolveShape($oldShape, $newShape);
        $newShape = self::resolveShape($newShape, $oldShape);

        if ($oldShape !== $newShape) {
            $entries[] = new DiffEntry($path, DiffKind::Changed, $old, $new);

            return;
        }

        $oldData = self::data($old);
        $newData = self::data($new);

        if (! is_array($oldData) || ! is_array($newData)) {
            $entries[] = new DiffEntry($path, DiffKind::Changed, $old, $new);

            return;
        }

        if ($oldShape === 'list') {
            self::walkList($oldData, $newData, $path, $entries);

            return;
        }

        self::walkObject($oldData, $newData, $path, $entries);
    }

    /**
     * @param  array<array-key, mixed>  $oldData
     * @param  array<array-key, mixed>  $newData
     * @param  list<DiffEntry>  $entries
     */
    private static function walkObject(array $oldData, array $newData, string $path, array &$entries): void
    {
        $keys = array_values(array_unique([...array_keys($oldData), ...array_keys($newData)]));

        foreach ($keys as $key) {
            $childPath = $path === '' ? (string) $key : $path.'.'.$key;
            $oldHas = array_key_exists($key, $oldData);
            $newHas = array_key_exists($key, $newData);

            if (! $oldHas) {
                $entries[] = new DiffEntry($childPath, DiffKind::Added, null, $newData[$key]);
            } elseif (! $newHas) {
                $entries[] = new DiffEntry($childPath, DiffKind::Removed, $oldData[$key], null);
            } else {
                self::walk($oldData[$key], $newData[$key], $childPath, $entries);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $oldData
     * @param  array<array-key, mixed>  $newData
     * @param  list<DiffEntry>  $entries
     */
    private static function walkList(array $oldData, array $newData, string $path, array &$entries): void
    {
        $count = max(count($oldData), count($newData));

        for ($index = 0; $index < $count; $index++) {
            $childPath = $path.'['.$index.']';
            $oldHas = array_key_exists($index, $oldData);
            $newHas = array_key_exists($index, $newData);

            if (! $oldHas) {
                $entries[] = new DiffEntry($childPath, DiffKind::Added, null, $newData[$index]);
            } elseif (! $newHas) {
                $entries[] = new DiffEntry($childPath, DiffKind::Removed, $oldData[$index], null);
            } else {
                self::walk($oldData[$index], $newData[$index], $childPath, $entries);
            }
        }
    }

    private static function shape(mixed $value): string
    {
        if ($value instanceof JsonObject) {
            return 'object';
        }

        if ($value instanceof JsonArray) {
            return 'list';
        }

        if (is_array($value)) {
            if ($value === []) {
                return 'ambiguous';
            }

            return array_is_list($value) ? 'list' : 'object';
        }

        return 'scalar';
    }

    private static function resolveShape(string $shape, string $other): string
    {
        if ($shape !== 'ambiguous') {
            return $shape;
        }

        return $other === 'ambiguous' ? 'object' : $other;
    }

    /**
     * Unwraps a single JsonObject/JsonArray wrapper layer, leaving the
     * child values untouched so a nested wrapper survives until its own
     * recursion level.
     */
    private static function data(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            return $value->toArray();
        }

        if ($value instanceof JsonArray) {
            return $value->toArray();
        }

        return $value;
    }

    private static function equalScalars(mixed $old, mixed $new): bool
    {
        if (is_int($old) && is_int($new)) {
            return $old === $new;
        }

        if ((is_int($old) || is_float($old)) && (is_int($new) || is_float($new))) {
            return (float) $old === (float) $new;
        }

        return $old === $new;
    }

    private static function formatValue(mixed $value): string
    {
        $value = self::data($value);
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? get_debug_type($value) : $encoded;
    }
}
