<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Json;

/**
 * Serializes a JSON-compatible structure into canonical JSON (JCS) per
 * RFC 8785: no whitespace, recursive object member sorting by UTF-16 code
 * units, minimal string escaping with lowercase \uhhhh forms for control
 * characters, and ECMAScript-compatible number serialization (shortest
 * round-trip representation, exponent form when the exponent is below -6
 * or at or above 21, no trailing zeros, negative zero as 0).
 *
 * Accepted values are null, booleans, integers, floats, strings, plain PHP
 * arrays (lists serialize as JSON arrays, associative arrays as JSON
 * objects), JsonObject and JsonArray. Anything else - including objects
 * and resources - throws an InvalidArgumentException, as does any string
 * that is not valid UTF-8 and any NaN or infinite float.
 *
 * The output contains no whitespace and every object member name is
 * serialized in ascending order of its UTF-16 code units, which makes the
 * result deterministic and usable as input to a hash function.
 */
final class CanonicalJsonSerializer
{
    private const MAX_DEPTH = 512;

    /**
     * Serializes the given value to canonical JSON (RFC 8785).
     *
     * @throws \InvalidArgumentException when the value is not valid JSON
     *                                   data (objects, resources, invalid
     *                                   UTF-8, NaN, infinity) or exceeds
     *                                   the maximum nesting depth.
     */
    public static function serialize(mixed $value): string
    {
        return self::serializeValue($value, 0);
    }

    private static function serializeValue(mixed $value, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException('Canonical JSON structure exceeds the maximum nesting depth of 512.');
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return self::serializeInteger($value);
        }

        if (is_float($value)) {
            return self::serializeNumber($value);
        }

        if (is_string($value)) {
            return self::serializeString($value);
        }

        if ($value instanceof JsonObject) {
            return self::serializeObject($value->toArray(), $depth);
        }

        if ($value instanceof JsonArray) {
            return self::serializeList($value->toArray(), $depth);
        }

        if (is_array($value)) {
            return array_is_list($value)
                ? self::serializeList($value, $depth)
                : self::serializeObject($value, $depth);
        }

        throw new \InvalidArgumentException(sprintf(
            'Value of type %s cannot be serialized to canonical JSON; expected scalars, arrays, JsonObject or JsonArray.',
            get_debug_type($value),
        ));
    }

    /**
     * @param  list<mixed>  $items
     */
    private static function serializeList(array $items, int $depth): string
    {
        $parts = [];

        foreach ($items as $item) {
            $parts[] = self::serializeValue($item, $depth + 1);
        }

        return '['.implode(',', $parts).']';
    }

    /**
     * Serializes an associative array as a JSON object with member names
     * sorted by UTF-16 code units. Integer keys (which PHP produces for
     * numeric JSON object member names) are serialized as decimal strings.
     *
     * @param  array<mixed, mixed>  $data
     */
    private static function serializeObject(array $data, int $depth): string
    {
        $names = array_map(
            static fn (int|string $key): string => (string) $key,
            array_keys($data),
        );

        $utf16 = [];

        foreach ($names as $name) {
            self::assertValidUtf8($name);
            $utf16[$name] = self::toUtf16($name);
        }

        usort($names, static function (string $left, string $right) use ($utf16): int {
            return strcmp($utf16[$left], $utf16[$right]);
        });

        $parts = [];

        foreach ($names as $name) {
            $parts[] = self::serializeString($name).':'.self::serializeValue($data[$name], $depth + 1);
        }

        return '{'.implode(',', $parts).'}';
    }

    /**
     * Serializes an integer per RFC 8785 section 3.2.2.2. Integers whose
     * absolute value exceeds 2^53 are outside the IEEE-754 binary64 safe
     * range and cannot be represented exactly as JSON numbers: they are
     * converted to double first and emitted with the ECMAScript
     * Number::toString rules (the same output JSON.stringify((Number)
     * $value) produces). Integers within the safe range keep their exact
     * decimal form.
     */
    private static function serializeInteger(int $value): string
    {
        if (abs($value) > 9007199254740992) {
            return self::serializeNumber((float) $value);
        }

        return (string) $value;
    }

    /**
     * Serializes a float per ECMAScript Number::toString semantics (RFC
     * 8785 section 3.2.2.3): the shortest decimal representation that
     * round-trips, in plain notation when the exponent of the leading
     * digit is between -6 and 20 inclusive, in exponential notation
     * otherwise, without trailing zeros and with negative zero as "0".
     */
    private static function serializeNumber(float $number): string
    {
        if (is_nan($number) || is_infinite($number)) {
            throw new \InvalidArgumentException('NaN and Infinity are not valid JSON numbers.');
        }

        if ($number == 0.0) {
            return '0';
        }

        [$digits, $significant, $point] = self::decompose(abs($number));

        if ($significant <= $point && $point <= 21) {
            $serialized = $digits.str_repeat('0', $point - $significant);
        } elseif ($point > 0 && $point <= 21) {
            $serialized = substr($digits, 0, $point).'.'.substr($digits, $point);
        } elseif ($point > -6 && $point <= 0) {
            $serialized = '0.'.str_repeat('0', -$point).$digits;
        } else {
            $exponent = $point - 1;
            $serialized = $digits[0].($significant > 1 ? '.'.substr($digits, 1) : '')
                .'e'.($exponent < 0 ? '-' : '+').(string) abs($exponent);
        }

        return $number < 0.0 ? '-'.$serialized : $serialized;
    }

    /**
     * Decomposes a positive finite float into the digits of its shortest
     * round-trip decimal representation, the number of significant
     * digits and the position of the decimal point (value equals
     * digits * 10^(point - significant)), matching ECMA-262 Number::toString.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private static function decompose(float $value): array
    {
        $candidate = null;

        for ($precision = 1; $precision <= 17; $precision++) {
            $candidate = self::shortestForm(self::formatGeneral($precision, $value));

            if ((float) $candidate === $value) {
                break;
            }
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $candidate, $matches) !== 1) {
            throw new \InvalidArgumentException(sprintf('Cannot serialize the float %s to canonical JSON.', $value));
        }

        $integerPart = $matches[1];
        $fractionPart = $matches[2] ?? '';
        $exponent = isset($matches[3]) ? (int) $matches[3] : 0;

        $combined = $integerPart.$fractionPart;
        $leadingZeros = strlen($combined) - strlen(ltrim($combined, '0'));
        $digits = ltrim($combined, '0');

        return [$digits, strlen($digits), strlen($integerPart) - $leadingZeros + $exponent];
    }

    /**
     * Drops trailing zeros from the fraction so the round-trip search
     * works on the minimal digit sequence: PHP %g pads the mantissa of
     * exponent forms (1e+30 is emitted as 1.0e+30).
     */
    private static function shortestForm(string $formatted): string
    {
        if (preg_match('/^(\d+(?:\.\d*)?)[eE]([+-]?\d+)$/', $formatted, $matches) === 1) {
            $mantissa = rtrim($matches[1], '0');

            if (str_ends_with($mantissa, '.')) {
                $mantissa = substr($mantissa, 0, -1);
            }

            return $mantissa.'e'.$matches[2];
        }

        return $formatted;
    }

    /**
     * Formats a float with the given number of significant digits using
     * %g, normalizing the locale decimal separator to a dot.
     */
    private static function formatGeneral(int $precision, float $value): string
    {
        $formatted = sprintf('%.*g', $precision, $value);
        $separator = localeconv()['decimal_point'];

        if ($separator !== '' && $separator !== '.') {
            $formatted = str_replace($separator, '.', $formatted);
        }

        return $formatted;
    }

    /**
     * Serializes a string with minimal escaping: quotation marks,
     * backslashes and control characters (U+0000 through U+001F) are
     * escaped, using \b \t \n \f \r where available and lowercase
     * \uhhhh otherwise; every other character is emitted literally as
     * UTF-8.
     */
    private static function serializeString(string $value): string
    {
        self::assertValidUtf8($value);

        $escaped = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $byte = $value[$i];
            $code = ord($byte);

            if ($code < 0x20) {
                $escaped .= match ($code) {
                    0x08 => '\\b',
                    0x09 => '\\t',
                    0x0A => '\\n',
                    0x0C => '\\f',
                    0x0D => '\\r',
                    default => sprintf('\\u%04x', $code),
                };
            } elseif ($code === 0x22) {
                $escaped .= '\\"';
            } elseif ($code === 0x5C) {
                $escaped .= '\\\\';
            } else {
                $escaped .= $byte;
            }
        }

        return '"'.$escaped.'"';
    }

    /**
     * Encodes a string to its UTF-16BE representation so that a plain
     * byte comparison corresponds exactly to a comparison of UTF-16 code
     * units (RFC 8785 section 3.2.3).
     */
    private static function toUtf16(string $value): string
    {
        return mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
    }

    private static function assertValidUtf8(string $value): void
    {
        if (preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException('String values must be valid UTF-8 for canonical JSON serialization.');
        }
    }
}
