<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Models\Json;

use Digitalygo\Envai\Models\Json\CanonicalJson;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Models\Json\RawJson;
use PHPUnit\Framework\TestCase;

final class JsonModelsTest extends TestCase
{
    public function test_empty_object_and_empty_list_and_null_stay_distinct(): void
    {
        $emptyObject = JsonObject::fromJson('{}');
        $emptyList = JsonArray::fromJson('[]');
        $null = (new RawJson('null'))->decode();

        $this->assertTrue($emptyObject->isEmpty());
        $this->assertSame([], $emptyObject->toArray());

        $this->assertTrue($emptyList->isEmpty());
        $this->assertSame([], $emptyList->toArray());

        $this->assertNull($null);
    }

    public function test_json_object_preserves_key_order(): void
    {
        $object = JsonObject::fromJson('{"z":1,"a":2,"m":3}');

        $this->assertSame(['z', 'a', 'm'], array_keys($object->toArray()));
    }

    public function test_json_object_get_and_has(): void
    {
        $object = JsonObject::fromJson('{"name":"x","nested":null}');

        $this->assertSame('x', $object->get('name'));
        $this->assertTrue($object->has('nested'));
        $this->assertFalse($object->has('absent'));
        $this->assertSame('fallback', $object->get('absent', 'fallback'));
    }

    public function test_json_object_from_json_rejects_array_literal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        JsonObject::fromJson('[]');
    }

    public function test_json_array_from_json_rejects_object_literal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        JsonArray::fromJson('{}');
    }

    public function test_raw_json_decodes_to_mixed_structures(): void
    {
        $this->assertSame(['a' => 1], (new RawJson('{"a":1}'))->decode());
        $this->assertSame(['x', 'y'], (new RawJson('["x","y"]'))->decode());
        $this->assertSame(5, (new RawJson('5'))->decode());
    }

    public function test_raw_json_serializes_as_itself(): void
    {
        $this->assertSame('{"a":1}', (new RawJson('{"a":1}'))->jsonSerialize());
    }

    public function test_json_object_serializes_back_to_equivalent_json(): void
    {
        $object = JsonObject::fromJson('{"a":1,"b":[true,null]}');

        $this->assertSame('{"a":1,"b":[true,null]}', json_encode($object));
    }

    public function test_canonical_json_accepts_scalar_arrays_and_wrappers(): void
    {
        $canonical = new CanonicalJson([
            'null' => null,
            'int' => 1,
            'bool' => true,
            'nested' => new JsonObject(['k' => new JsonArray([1, 2])]),
        ]);

        $this->assertSame(1, $canonical->value()['int']);
    }

    public function test_canonical_json_rejects_objects(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CanonicalJson(new \stdClass);
    }

    public function test_canonical_json_rejects_resources(): void
    {
        $resource = fopen('php://memory', 'r');
        $this->assertNotFalse($resource);

        try {
            $this->expectException(\InvalidArgumentException::class);

            new CanonicalJson($resource);
        } finally {
            fclose($resource);
        }
    }
}
