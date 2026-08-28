<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Security;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Models\Json\JsonObject;
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
