<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Json;

/**
 * Value object over a JSON-compatible structure destined for RFC 8785
 * canonicalization (JCS).
 *
 * No canonicalization happens here: a later wave owns the algorithm. The
 * constructor validates that the structure only contains scalars, arrays,
 * JsonObject, JsonArray and null, so the future hasher can rely on it.
 */
final readonly class CanonicalJson
{
    public function __construct(public mixed $value)
    {
        self::assertCanonicalizable($value, 0);
    }

    public function value(): mixed
    {
        return $this->value;
    }

    private static function assertCanonicalizable(mixed $value, int $depth): void
    {
        if ($depth > 512) {
            throw new \InvalidArgumentException('CanonicalJson structure is too deeply nested.');
        }

        if ($value === null || is_scalar($value)) {
            return;
        }

        if ($value instanceof JsonObject) {
            foreach ($value->toArray() as $item) {
                self::assertCanonicalizable($item, $depth + 1);
            }

            return;
        }

        if ($value instanceof JsonArray) {
            foreach ($value->toArray() as $item) {
                self::assertCanonicalizable($item, $depth + 1);
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertCanonicalizable($item, $depth + 1);
            }

            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'CanonicalJson accepts scalars, arrays, JsonObject, JsonArray and null only; got %s.',
            get_debug_type($value),
        ));
    }
}
