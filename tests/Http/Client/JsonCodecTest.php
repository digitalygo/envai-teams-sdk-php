<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Http\Client\JsonCodec;
use Digitalygo\Envai\Models\Common\ResourceCollection;
use Digitalygo\Envai\Models\Json\CanonicalJson;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Models\Json\RawJson;
use PHPUnit\Framework\TestCase;

final class JsonCodecTest extends TestCase
{
    private JsonCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new JsonCodec;
    }

    public function test_encode_null_body_returns_null(): void
    {
        $this->assertNull($this->codec->encodeRequestBody(null));
    }

    public function test_encode_string_body_is_passed_through(): void
    {
        $this->assertSame('{"raw":true}', $this->codec->encodeRequestBody('{"raw":true}'));
    }

    public function test_encode_json_object_unescapes_slashes_and_unicode(): void
    {
        $body = $this->codec->encodeRequestBody(new JsonObject(['url' => 'https://x/y', 'name' => 'café']));

        $this->assertSame('{"url":"https://x/y","name":"café"}', $body);
    }

    public function test_encode_json_array(): void
    {
        $this->assertSame('["a",1,null]', $this->codec->encodeRequestBody(new JsonArray(['a', 1, null])));
    }

    public function test_encode_plain_array_and_scalars(): void
    {
        $this->assertSame('{"a":1}', $this->codec->encodeRequestBody(['a' => 1]));
        $this->assertSame('42', $this->codec->encodeRequestBody(42));
    }

    public function test_encode_canonical_json_value(): void
    {
        $canonical = new CanonicalJson(['a' => 1, 'b' => new JsonObject(['c' => 2])]);

        $this->assertSame('{"a":1,"b":{"c":2}}', $this->codec->encodeRequestBody($canonical));
    }

    public function test_encode_raw_json_is_passed_through_verbatim(): void
    {
        $this->assertSame('{"raw":true}', $this->codec->encodeRequestBody(new RawJson('{"raw":true}')));
    }

    public function test_decode_top_level_preserves_unknown_fields_in_extra(): void
    {
        $resource = $this->codec->decodeTopLevelInto(
            '{"id":"r1","name":"alpha","future_field":{"nested":true}}',
            TestResource::class,
        );

        $this->assertInstanceOf(TestResource::class, $resource);
        $this->assertSame('r1', $resource->id);
        $this->assertSame('alpha', $resource->name);
        $this->assertSame(['future_field' => ['nested' => true]], $resource->extra);
    }

    public function test_decode_envelope_unwraps_single_resource(): void
    {
        $resource = $this->codec->decodeEnvelopeInto(
            '{"data":{"id":"r1","name":"alpha"}}',
            TestResource::class,
        );

        $this->assertInstanceOf(TestResource::class, $resource);
        $this->assertSame('r1', $resource->id);
    }

    public function test_decode_envelope_accepts_empty_object_data(): void
    {
        $resource = $this->codec->decodeEnvelopeInto('{"data":{}}', TestResource::class);

        $this->assertInstanceOf(TestResource::class, $resource);
        $this->assertSame('', $resource->id);
    }

    public function test_decode_envelope_missing_wrapper_throws_protocol_violation(): void
    {
        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('single-resource');

        $this->codec->decodeEnvelopeInto('{"id":"r1"}', TestResource::class);
    }

    public function test_decode_envelope_rejects_array_data(): void
    {
        $this->expectException(ProtocolViolationException::class);

        $this->codec->decodeEnvelopeInto('{"data":[{"id":"r1"}]}', TestResource::class);
    }

    public function test_decode_collection_hydrates_resource_collection(): void
    {
        $collection = $this->codec->decodeCollectionInto(
            '{"data":[{"id":"r1"},{"id":"r2","name":"beta"}]}',
            TestResource::class,
        );

        $this->assertInstanceOf(ResourceCollection::class, $collection);
        $this->assertCount(2, $collection->items);
        $this->assertSame('r1', $collection->items[0]->id);
        $this->assertSame('beta', $collection->items[1]->name);
        $this->assertSame(TestResource::class, $collection->itemClass);
    }

    public function test_decode_collection_accepts_empty_data(): void
    {
        $collection = $this->codec->decodeCollectionInto('{"data":[]}', TestResource::class);

        $this->assertInstanceOf(ResourceCollection::class, $collection);
        $this->assertSame([], $collection->items);
    }

    public function test_decode_collection_rejects_non_list_data(): void
    {
        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('collection');

        $this->codec->decodeCollectionInto('{"data":{"id":"r1"}}', TestResource::class);
    }

    public function test_decode_malformed_json_throws_protocol_violation(): void
    {
        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('Malformed JSON');

        $this->codec->decodeTopLevelInto('{not json', TestResource::class);
    }

    public function test_decode_non_object_payload_throws_protocol_violation(): void
    {
        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('JSON object');

        $this->codec->decodeTopLevelInto('[1,2,3]', TestResource::class);
    }

    public function test_decode_rejects_class_without_array_hydratable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->codec->decodeTopLevelInto('{"id":"r1"}', \stdClass::class);
    }
}
