<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Security;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Events\AgentToolRequestEvent;
use Digitalygo\Envai\Streaming\Events\ToolSignature;
use Digitalygo\Envai\Tests\Tools\Support\FakeJwksTransport;
use Digitalygo\Envai\Tests\Tools\Support\TestKeys;
use Digitalygo\Envai\Tests\Tools\Support\ToolRequestFactory;
use Digitalygo\Envai\Tools\Security\JwksResolver;
use Digitalygo\Envai\Tools\Security\ToolRequestVerifier;
use PHPUnit\Framework\TestCase;

final class ToolRequestVerifierTest extends TestCase
{
    private const JWKS_URL = 'https://control.example/.well-known/jwks.json';

    private const CALL_ID = 'call_01';

    private const TOOL_NAME = 'lookup_order';

    /**
     * @param  array<string, mixed>  $document
     */
    private function verifier(array $document): ToolRequestVerifier
    {
        $transport = new FakeJwksTransport;
        $transport->document = $document;

        return new ToolRequestVerifier(new JwksResolver($transport, self::JWKS_URL));
    }

    public function test_valid_signature_verifies(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $event = ToolRequestFactory::signed(self::CALL_ID, self::TOOL_NAME, ['order_id' => 'ord_123'], $keys['secret'], $kid);

        $verified = $verifier->verify($event);

        $this->assertSame(self::CALL_ID, $verified->callId);
        $this->assertSame(self::TOOL_NAME, $verified->toolName);
        $this->assertSame(['order_id' => 'ord_123'], $verified->args->toArray());
        $this->assertSame($event->signature, $verified->signature);
    }

    public function test_padded_base64url_signature_is_accepted(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $unpadded = TestKeys::sign(self::CALL_ID, self::TOOL_NAME, ['order_id' => 'ord_123'], $keys['secret']);
        $padded = $unpadded.str_repeat('=', (4 - strlen($unpadded) % 4) % 4);

        $event = ToolRequestFactory::create(
            self::CALL_ID,
            self::TOOL_NAME,
            new JsonObject(['order_id' => 'ord_123']),
            new ToolSignature('EdDSA', $kid, $padded),
        );

        $verified = $verifier->verify($event);

        $this->assertSame(self::CALL_ID, $verified->callId);
    }

    public function test_wrong_key_fails_verification(): void
    {
        $keysA = TestKeys::generate();
        $keysB = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keysA['public']));

        $event = ToolRequestFactory::signed(self::CALL_ID, self::TOOL_NAME, ['order_id' => 'ord_123'], $keysB['secret'], $kid);

        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('does not verify');

        $verifier->verify($event);
    }

    public function test_tampered_preimage_fails_verification(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $signature = new ToolSignature(
            'EdDSA',
            $kid,
            TestKeys::sign(self::CALL_ID, self::TOOL_NAME, ['order_id' => 'ord_123'], $keys['secret']),
        );

        $event = ToolRequestFactory::create(
            self::CALL_ID,
            self::TOOL_NAME,
            new JsonObject(['order_id' => 'ord_999']),
            $signature,
        );

        $this->expectException(ProtocolViolationException::class);

        $verifier->verify($event);
    }

    public function test_non_edd_algorithm_is_rejected(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $event = ToolRequestFactory::create(
            self::CALL_ID,
            self::TOOL_NAME,
            new JsonObject(['order_id' => 'ord_123']),
            new ToolSignature('RS256', $kid, 'AAAA'),
        );

        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('algorithm must be EdDSA');

        $verifier->verify($event);
    }

    public function test_non_base64url_signature_is_rejected(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $event = ToolRequestFactory::create(
            self::CALL_ID,
            self::TOOL_NAME,
            new JsonObject(['order_id' => 'ord_123']),
            new ToolSignature('EdDSA', $kid, '!!!not-base64!!!'),
        );

        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('not valid base64url');

        $verifier->verify($event);
    }

    public function test_signature_of_wrong_length_is_rejected(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $event = ToolRequestFactory::create(
            self::CALL_ID,
            self::TOOL_NAME,
            new JsonObject(['order_id' => 'ord_123']),
            new ToolSignature('EdDSA', $kid, TestKeys::b64url(random_bytes(10))),
        );

        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('invalid length');

        $verifier->verify($event);
    }

    public function test_public_key_of_wrong_length_is_rejected(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $entry = TestKeys::jwkEntry($kid, $keys['public']);
        $entry['x'] = TestKeys::b64url('too short');
        $verifier = $this->verifier(['keys' => [$entry]]);

        $event = ToolRequestFactory::signed(self::CALL_ID, self::TOOL_NAME, ['order_id' => 'ord_123'], $keys['secret'], $kid);

        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('invalid length');

        $verifier->verify($event);
    }

    public function test_key_with_wrong_crv_is_rejected(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $entry = TestKeys::jwkEntry($kid, $keys['public']);
        $entry['crv'] = 'P-256';
        $verifier = $this->verifier(['keys' => [$entry]]);

        $event = ToolRequestFactory::signed(self::CALL_ID, self::TOOL_NAME, ['order_id' => 'ord_123'], $keys['secret'], $kid);

        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('not an Ed25519 signature key');

        $verifier->verify($event);
    }

    public function test_key_with_conflicting_algorithm_is_rejected(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $entry = TestKeys::jwkEntry($kid, $keys['public']);
        $entry['alg'] = 'RS256';
        $verifier = $this->verifier(['keys' => [$entry]]);
        $event = ToolRequestFactory::signed(self::CALL_ID, self::TOOL_NAME, [], $keys['secret'], $kid);

        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('not an Ed25519 signature key');

        $verifier->verify($event);
    }

    public function test_unknown_kid_after_refresh_is_rejected(): void
    {
        $keys = TestKeys::generate();
        $transport = new FakeJwksTransport;
        $transport->document = TestKeys::jwkDocument(TestKeys::kid(), $keys['public']);
        $verifier = new ToolRequestVerifier(new JwksResolver($transport, self::JWKS_URL));

        // Prime the cache with the known key first, then verify an event
        // whose kid is unknown: exactly one forced refresh happens before
        // the rejection.
        $verifier->verify(ToolRequestFactory::signed(self::CALL_ID, self::TOOL_NAME, ['order_id' => 'ord_123'], $keys['secret'], TestKeys::kid()));

        $event = ToolRequestFactory::signed(self::CALL_ID, self::TOOL_NAME, ['order_id' => 'ord_123'], $keys['secret'], 'sig_missing');

        $this->expectException(ProtocolViolationException::class);
        $this->expectExceptionMessage('Unknown signing key');

        try {
            $verifier->verify($event);
        } finally {
            $this->assertSame(2, $transport->fetchCount);
        }
    }

    public function test_failure_messages_never_leak_preimage_or_key_material(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $signature = new ToolSignature(
            'EdDSA',
            $kid,
            TestKeys::sign(self::CALL_ID, self::TOOL_NAME, ['order_id' => 'ord_123'], $keys['secret']),
        );

        $event = ToolRequestFactory::create(
            self::CALL_ID,
            self::TOOL_NAME,
            new JsonObject(['order_id' => 'ord_999']),
            $signature,
        );

        try {
            $verifier->verify($event);
            $this->fail('Expected a ProtocolViolationException.');
        } catch (ProtocolViolationException $exception) {
            $message = $exception->getMessage();

            $this->assertStringNotContainsString('ord_999', $message);
            $this->assertStringNotContainsString('ord_123', $message);
            $this->assertStringNotContainsString($event->signature->sig, $message);
            $this->assertStringNotContainsString(TestKeys::b64url($keys['public']), $message);
        }
    }

    public function test_empty_object_preimage_matches_the_gateway_for_missing_args(): void
    {
        $keys = TestKeys::generate();
        $kid = 'sig_01M0QH1AJB2NVWZZJ2P8XET3F7';
        $callId = 'tc_06G4HP0SC1Z23CCNPBT0CAB49C';
        $toolName = 'list_debug_files';
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        // The gateway signs the preimage call_id + "\n" + tool_name +
        // "\n" + canonical_json(args) and falls back to `{}` when args is
        // missing; the verifier must produce exactly that canonical form
        // for an empty JsonObject, never `[]`.
        $preimage = $callId."\n".$toolName."\n{}";
        $signature = new ToolSignature('EdDSA', $kid, TestKeys::signPayload($preimage, $keys['secret']));

        $event = ToolRequestFactory::create($callId, $toolName, new JsonObject([]), $signature);

        $verified = $verifier->verify($event);

        $this->assertSame($callId, $verified->callId);
        $this->assertSame($toolName, $verified->toolName);
        $this->assertSame('{}', CanonicalJsonSerializer::serialize($event->args));
    }

    public function test_wire_event_without_args_verifies_end_to_end_against_the_gateway_preimage(): void
    {
        $keys = TestKeys::generate();
        $kid = 'sig_01M0QH1AJB2NVWZZJ2P8XET3F7';
        $callId = 'tc_06G4HP0SC1Z23CCNPBT0CAB49C';
        $toolName = 'list_debug_files';
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        // Real wire capture shape: the alpha gateway omits `args` when the
        // LLM passes no arguments, and signs over the empty object `{}`.
        $preimage = $callId."\n".$toolName."\n{}";
        $signature = TestKeys::signPayload($preimage, $keys['secret']);

        $event = (new AtpEventDecoder)->decode($this->requestWire($callId, $toolName, $kid, $signature));

        $this->assertInstanceOf(AgentToolRequestEvent::class, $event);
        $this->assertSame('{}', CanonicalJsonSerializer::serialize($event->args));

        $verified = $verifier->verify($event);

        $this->assertSame($callId, $verified->callId);
        $this->assertSame($toolName, $verified->toolName);
    }

    public function test_explicit_empty_object_args_verify_against_the_gateway_preimage(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $preimage = self::CALL_ID."\n".self::TOOL_NAME."\n{}";
        $signature = TestKeys::signPayload($preimage, $keys['secret']);

        $event = (new AtpEventDecoder)->decode($this->requestWire(self::CALL_ID, self::TOOL_NAME, $kid, $signature, new \stdClass));

        $this->assertInstanceOf(AgentToolRequestEvent::class, $event);
        $this->assertSame('{}', CanonicalJsonSerializer::serialize($event->args));

        $verified = $verifier->verify($event);

        $this->assertSame(self::CALL_ID, $verified->callId);
    }

    public function test_simple_args_preimage_uses_the_gateway_canonical_form(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $preimage = self::CALL_ID."\n".self::TOOL_NAME."\n{\"subpath\":\"notes\"}";
        $signature = new ToolSignature('EdDSA', $kid, TestKeys::signPayload($preimage, $keys['secret']));

        $event = ToolRequestFactory::create(self::CALL_ID, self::TOOL_NAME, new JsonObject(['subpath' => 'notes']), $signature);

        $verified = $verifier->verify($event);

        $this->assertSame(self::CALL_ID, $verified->callId);
    }

    public function test_nested_empty_objects_keep_object_semantics_in_the_preimage(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $callId = 'call_nested_01';
        $toolName = 'run_report';
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        // Nested empty objects must canonicalize to `{}` at every depth
        // (key-sorted), matching the gateway signer over the raw decoded
        // structure: {"a":{},"b":[1,2],"c":{"d":{}}}.
        $canonical = '{"a":{},"b":[1,2],"c":{"d":{}}}';
        $preimage = $callId."\n".$toolName."\n".$canonical;
        $signature = TestKeys::signPayload($preimage, $keys['secret']);

        $args = ['a' => new \stdClass, 'b' => [1, 2], 'c' => ['d' => new \stdClass]];
        $event = (new AtpEventDecoder)->decode($this->requestWire($callId, $toolName, $kid, $signature, $args));

        $this->assertInstanceOf(AgentToolRequestEvent::class, $event);
        $this->assertSame($canonical, CanonicalJsonSerializer::serialize($event->args));

        $verified = $verifier->verify($event);

        $this->assertSame($callId, $verified->callId);
    }

    /**
     * Builds an `agent.tool.request` wire envelope for the given data.
     *
     * @param  array<string, mixed>  $signature  the signature object fields.
     * @param  array<string, mixed>|\stdClass|null  $args  optional args object.
     */
    private function requestWire(string $callId, string $toolName, string $kid, string $signature, array|\stdClass|null $args = null): string
    {
        $data = [
            'kind' => 'client',
            'call_id' => $callId,
            'signature' => ['alg' => 'EdDSA', 'kid' => $kid, 'sig' => $signature],
            'tool_name' => $toolName,
        ];

        if ($args !== null) {
            $data['args'] = $args;
        }

        return json_encode([
            'v' => 3,
            'id' => 'evt_01M0QH1AJB2NVWZZJ2P8XET3FA',
            'seq' => 1,
            'ts' => '2026-08-28T14:00:00Z',
            'chat_id' => 'cht_01M0QH1AJB2NVWZZJ2P8XET3F7',
            'turn_id' => 'cmpl_01M0QH1AJB2NVWZZJ2P8XET3F7',
            'agent' => null,
            'type' => 'agent.tool.request',
            'data' => $data,
        ], JSON_THROW_ON_ERROR);
    }

    public function test_rfc8785_preimage_matches_the_hand_computed_canonical_form(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $args = ['unicode' => 'café 🚀', 'nested' => ['b' => 2, 'a' => 1], 'pi' => 3.14159, 'list' => [3, 1, 2]];
        $canonical = '{"list":[3,1,2],"nested":{"a":1,"b":2},"pi":3.14159,"unicode":"café 🚀"}';
        $preimage = self::CALL_ID."\n".self::TOOL_NAME."\n".$canonical;
        $signature = new ToolSignature('EdDSA', $kid, TestKeys::signPayload($preimage, $keys['secret']));

        $event = ToolRequestFactory::create(self::CALL_ID, self::TOOL_NAME, new JsonObject($args), $signature);

        $verified = $verifier->verify($event);

        $this->assertSame(self::CALL_ID, $verified->callId);
    }

    public function test_utf16_key_sorting_matches_rfc8785(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $args = ['é' => 2, 'e' => 1, 'z' => 3];
        $canonical = '{"e":1,"z":3,"é":2}';
        $preimage = self::CALL_ID."\n".self::TOOL_NAME."\n".$canonical;
        $signature = new ToolSignature('EdDSA', $kid, TestKeys::signPayload($preimage, $keys['secret']));

        $event = ToolRequestFactory::create(self::CALL_ID, self::TOOL_NAME, new JsonObject($args), $signature);

        $verified = $verifier->verify($event);

        $this->assertSame(self::CALL_ID, $verified->callId);
    }

    public function test_exponent_number_forms_match_rfc8785(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        $args = ['n1' => 1e30, 'n2' => 1e-7];
        $canonical = '{"n1":1e+30,"n2":1e-7}';
        $preimage = self::CALL_ID."\n".self::TOOL_NAME."\n".$canonical;
        $signature = new ToolSignature('EdDSA', $kid, TestKeys::signPayload($preimage, $keys['secret']));

        $event = ToolRequestFactory::create(self::CALL_ID, self::TOOL_NAME, new JsonObject($args), $signature);

        $verified = $verifier->verify($event);

        $this->assertSame(self::CALL_ID, $verified->callId);
    }

    public function test_non_canonical_preimage_is_rejected(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid();
        $verifier = $this->verifier(TestKeys::jwkDocument($kid, $keys['public']));

        // Signed over PHP's natural key order, not the RFC 8785 sorted order.
        $nonCanonical = json_encode(['b' => 1, 'a' => 2], JSON_THROW_ON_ERROR);
        $preimage = self::CALL_ID."\n".self::TOOL_NAME."\n".$nonCanonical;
        $signature = new ToolSignature('EdDSA', $kid, TestKeys::signPayload($preimage, $keys['secret']));

        $event = ToolRequestFactory::create(self::CALL_ID, self::TOOL_NAME, new JsonObject(['a' => 2, 'b' => 1]), $signature);

        $this->expectException(ProtocolViolationException::class);

        $verifier->verify($event);
    }
}
