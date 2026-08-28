<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Models;

use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonSerializerTest extends TestCase
{
    public function test_rfc8785_section_322_example_numbers_string_and_literals(): void
    {
        $input = [
            'numbers' => [333333333.33333329, 1E30, 4.50, 2e-3, 1e-27],
            'string' => "\u{20ac}\$\x0F\nA'B\"\\\\\"/",
            'literals' => [null, true, false],
        ];

        // Expected serialized string content: € $ \u000f \n A ' B " \ \ " /
        $expected = '{"literals":[null,true,false],"numbers":[333333333.3333333,1e+30,4.5,0.002,1e-27],"string":"€$'
            .'\\u000f\\nA\'B'
            .'\\"'.'\\\\\\\\'.'\\"'
            .'/"}';

        $this->assertSame($expected, CanonicalJsonSerializer::serialize($input));
    }

    public function test_rfc8785_section_324_byte_exact_output(): void
    {
        $input = [
            'numbers' => [333333333.33333329, 1E30, 4.50, 2e-3, 1e-27],
            'string' => "\u{20ac}\$\x0F\nA'B\"\\\\\"/",
            'literals' => [null, true, false],
        ];

        $expectedHex = '7b226c69746572616c73223a5b6e756c6c2c747275652c66616c73655d2c226e756d62657273223a5b3333333333333333332e333333333333332c31652b33302c342e352c302e3030322c31652d32375d2c22737472696e67223a22e282ac245c75303030665c6e4127425c225c5c5c5c5c222f227d';

        $this->assertSame($expectedHex, bin2hex(CanonicalJsonSerializer::serialize($input)));
    }

    public function test_rfc8785_section_323_sorting_by_utf16_code_units(): void
    {
        $input = [
            "\u{20ac}" => 'Euro Sign',
            "\r" => 'Carriage Return',
            "\u{FB33}" => 'Hebrew Letter Dalet With Dagesh',
            '1' => 'One',
            "\u{1F600}" => 'Emoji: Grinning Face',
            "\u{80}" => 'Control',
            "\u{F6}" => 'Latin Small Letter O With Diaeresis',
        ];

        $expected = '{"\r":"Carriage Return","1":"One","'."\u{80}".'":"Control","'."\u{F6}".'":"Latin Small Letter O With Diaeresis","'."\u{20ac}".'":"Euro Sign","'."\u{1F600}".'":"Emoji: Grinning Face","'."\u{FB33}".'":"Hebrew Letter Dalet With Dagesh"}';

        $this->assertSame($expected, CanonicalJsonSerializer::serialize($input));
    }

    public function test_sorting_places_supplementary_plane_after_bmp_characters(): void
    {
        $input = [
            'aa' => 'The first key',
            "\u{F6}" => 'Latin small letter o with diaeresis',
            "\u{1F351}" => 'Peach emoji',
            'z' => 'The fifth key',
            "\u{20ac}" => 'Euro sign',
        ];

        // UTF-16 code units: aa (61 61), z (7a), ö (00f6), € (20ac), 🍑 (d83d df51)
        $expected = '{"aa":"The first key","z":"The fifth key","'."\u{F6}".'":"Latin small letter o with diaeresis","'."\u{20ac}".'":"Euro sign","'."\u{1F351}".'":"Peach emoji"}';

        $this->assertSame($expected, CanonicalJsonSerializer::serialize($input));
    }

    public function test_rfc8785_appendix_e_string_subtype_example(): void
    {
        $input = ['time' => '2019-01-28T07:45:10Z', 'big' => '055', 'val' => 3.5];

        $this->assertSame('{"big":"055","time":"2019-01-28T07:45:10Z","val":3.5}', CanonicalJsonSerializer::serialize($input));
    }

    public function test_appendix_b_number_serialization_samples(): void
    {
        $cases = [
            [0.0, '0'],
            [-0.0, '0'],
            [5e-324, '5e-324'],
            [-5e-324, '-5e-324'],
            [1.7976931348623157e308, '1.7976931348623157e+308'],
            [-1.7976931348623157e308, '-1.7976931348623157e+308'],
            [9007199254740992, '9007199254740992'],
            [-9007199254740992, '-9007199254740992'],
            [295147905179352830000, '295147905179352830000'],
            [9.999999999999997e22, '9.999999999999997e+22'],
            [1e23, '1e+23'],
            [1.0000000000000001e23, '1.0000000000000001e+23'],
            [999999999999999700000.0, '999999999999999700000'],
            [999999999999999900000.0, '999999999999999900000'],
            [1e21, '1e+21'],
            [9.999999999999997e-7, '9.999999999999997e-7'],
            [0.000001, '0.000001'],
            [333333333.3333332, '333333333.3333332'],
            [333333333.33333325, '333333333.33333325'],
            [333333333.3333333, '333333333.3333333'],
            [333333333.3333334, '333333333.3333334'],
            [333333333.33333343, '333333333.33333343'],
            [-0.0000033333333333333333, '-0.0000033333333333333333'],
            [1424953923781206.2, '1424953923781206.2'],
        ];

        foreach ($cases as [$number, $expected]) {
            $this->assertSame($expected, CanonicalJsonSerializer::serialize($number), 'serializing '.$number);
        }
    }

    public function test_integer_numbers_within_the_safe_range_are_serialized_plain(): void
    {
        $this->assertSame('42', CanonicalJsonSerializer::serialize(42));
        $this->assertSame('-9007199254740991', CanonicalJsonSerializer::serialize(-9007199254740991));
        $this->assertSame('9007199254740991', CanonicalJsonSerializer::serialize(9007199254740991));
        $this->assertSame('9007199254740992', CanonicalJsonSerializer::serialize(9007199254740992));
        $this->assertSame('-9007199254740992', CanonicalJsonSerializer::serialize(-9007199254740992));
    }

    public function test_integers_beyond_the_safe_range_serialize_as_doubles_per_rfc8785(): void
    {
        // Integers with |value| > 2**53 cannot be represented exactly as
        // JSON numbers: the value is converted to double (round to
        // nearest even) and serialized with the ECMAScript rules, i.e.
        // the same output JSON.stringify((Number) $value) produces.
        $this->assertSame('9007199254740992', CanonicalJsonSerializer::serialize(9007199254740993));
        $this->assertSame('-9007199254740992', CanonicalJsonSerializer::serialize(-9007199254740993));
        $this->assertSame('9223372036854776000', CanonicalJsonSerializer::serialize(PHP_INT_MAX));
        $this->assertSame('-9223372036854776000', CanonicalJsonSerializer::serialize(-PHP_INT_MAX));
    }

    public function test_exponent_thresholds_follow_ecmascript_rules(): void
    {
        $this->assertSame('100000000000000000000', CanonicalJsonSerializer::serialize(1e20));
        $this->assertSame('1e+21', CanonicalJsonSerializer::serialize(1e21));
        $this->assertSame('0.000001', CanonicalJsonSerializer::serialize(1e-6));
        $this->assertSame('1e-7', CanonicalJsonSerializer::serialize(1e-7));
    }

    public function test_string_escaping_is_minimal(): void
    {
        $input = "\"\\\x00\x01\x08\x09\x0A\x0C\x0D\x1F";

        $this->assertSame('"\\"\\\\\\u0000\\u0001\\b\\t\\n\\f\\r\\u001f"', CanonicalJsonSerializer::serialize($input));
    }

    public function test_control_character_escapes_use_lowercase_hex(): void
    {
        $this->assertSame('"\u000b"', CanonicalJsonSerializer::serialize("\x0B"));
        $this->assertSame('"\u001f"', CanonicalJsonSerializer::serialize("\x1F"));
    }

    public function test_non_ascii_strings_are_emitted_literally_as_utf8(): void
    {
        $this->assertSame('"€é😀"', CanonicalJsonSerializer::serialize('€é😀'));
    }

    public function test_empty_object_empty_array_and_null_stay_distinct(): void
    {
        $this->assertSame('{}', CanonicalJsonSerializer::serialize(new JsonObject([])));
        $this->assertSame('[]', CanonicalJsonSerializer::serialize(new JsonArray([])));
        $this->assertSame('[]', CanonicalJsonSerializer::serialize([]));
        $this->assertSame('[1,2]', CanonicalJsonSerializer::serialize([1, 2]));
        $this->assertSame('null', CanonicalJsonSerializer::serialize(null));
    }

    public function test_nested_structures_sort_recursively_but_keep_array_order(): void
    {
        $input = [
            'b' => [3, 1, 2],
            'a' => ['y' => 2, 'x' => ['d' => 4, 'c' => 3]],
        ];

        $expected = '{"a":{"x":{"c":3,"d":4},"y":2},"b":[3,1,2]}';

        $this->assertSame($expected, CanonicalJsonSerializer::serialize($input));
    }

    public function test_numeric_string_keys_are_serialized_as_object_members(): void
    {
        $this->assertSame('{"0":"x","1":"y"}', CanonicalJsonSerializer::serialize(new JsonObject(['0' => 'x', '1' => 'y'])));
        $this->assertSame('{"1":"x","3":"y"}', CanonicalJsonSerializer::serialize([1 => 'x', 3 => 'y']));
    }

    public function test_determinism_across_key_insertion_order(): void
    {
        $first = CanonicalJsonSerializer::serialize(['z' => 1, 'a' => ['y' => 2, 'b' => 3], 'm' => null]);
        $second = CanonicalJsonSerializer::serialize(['m' => null, 'a' => ['b' => 3, 'y' => 2], 'z' => 1]);

        $this->assertSame($first, $second);
    }

    public function test_json_object_and_equivalent_array_serialize_identically(): void
    {
        $array = CanonicalJsonSerializer::serialize(['a' => 1, 'b' => ['c' => true]]);
        $object = CanonicalJsonSerializer::serialize(new JsonObject(['a' => 1, 'b' => new JsonObject(['c' => true])]));

        $this->assertSame($array, $object);
    }

    public function test_rejects_nan_and_infinity(): void
    {
        foreach ([NAN, INF, -INF] as $number) {
            try {
                CanonicalJsonSerializer::serialize($number);
                $this->fail('NaN/Infinity must be rejected.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('NaN and Infinity', $exception->getMessage());
            }
        }
    }

    public function test_rejects_objects(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalJsonSerializer::serialize(new \stdClass);
    }

    public function test_rejects_resources(): void
    {
        $resource = fopen('php://memory', 'r');
        $this->assertNotFalse($resource);

        try {
            $this->expectException(\InvalidArgumentException::class);

            CanonicalJsonSerializer::serialize($resource);
        } finally {
            fclose($resource);
        }
    }

    public function test_rejects_invalid_utf8_strings(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalJsonSerializer::serialize("\xC3\x28");
    }

    public function test_rejects_invalid_utf8_object_keys(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CanonicalJsonSerializer::serialize(["\xFF" => 'broken']);
    }

    public function test_rejects_excessive_nesting(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $value = null;

        for ($i = 0; $i < 600; $i++) {
            $value = [$value];
        }

        CanonicalJsonSerializer::serialize($value);
    }
}
