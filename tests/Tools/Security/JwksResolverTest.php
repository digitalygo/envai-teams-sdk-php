<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Security;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Tests\Tools\Support\FakeJwksTransport;
use Digitalygo\Envai\Tests\Tools\Support\TestKeys;
use Digitalygo\Envai\Tools\Security\JwksResolver;
use PHPUnit\Framework\TestCase;

final class JwksResolverTest extends TestCase
{
    private const JWKS_URL = 'https://control.example/.well-known/jwks.json';

    public function test_get_returns_the_key_from_the_fetched_document(): void
    {
        $keys = TestKeys::generate();
        $transport = new FakeJwksTransport;
        $transport->document = TestKeys::jwkDocument(TestKeys::kid(), $keys['public']);
        $resolver = new JwksResolver($transport, self::JWKS_URL);

        $entry = $resolver->get(TestKeys::kid());

        $this->assertSame('OKP', $entry->kty);
        $this->assertSame('Ed25519', $entry->crv);
        $this->assertSame(TestKeys::b64url($keys['public']), $entry->x);
        $this->assertSame(TestKeys::kid(), $entry->kid);
        $this->assertSame('sig', $entry->use);
        $this->assertSame('EdDSA', $entry->alg);
        $this->assertSame(1, $transport->fetchCount);
    }

    public function test_document_is_cached_within_the_ttl(): void
    {
        $keys = TestKeys::generate();
        $transport = new FakeJwksTransport;
        $transport->document = TestKeys::jwkDocument(TestKeys::kid(), $keys['public']);
        $resolver = new JwksResolver($transport, self::JWKS_URL);

        $resolver->get(TestKeys::kid());
        $resolver->get(TestKeys::kid());
        $resolver->get(TestKeys::kid());

        $this->assertSame(1, $transport->fetchCount);
    }

    public function test_document_is_refetched_after_the_ttl_expires(): void
    {
        $keys = TestKeys::generate();
        $transport = new FakeJwksTransport;
        $transport->document = TestKeys::jwkDocument(TestKeys::kid(), $keys['public']);
        $now = new \DateTimeImmutable('2026-08-17T14:00:00+00:00');
        $resolver = new JwksResolver(
            $transport,
            self::JWKS_URL,
            ttlSeconds: 60,
            clock: static function () use (&$now): \DateTimeImmutable {
                return $now;
            },
        );

        $resolver->get(TestKeys::kid());
        $now = $now->modify('+30 seconds');
        $resolver->get(TestKeys::kid());
        $this->assertSame(1, $transport->fetchCount);

        $now = $now->modify('+31 seconds');
        $resolver->get(TestKeys::kid());
        $this->assertSame(2, $transport->fetchCount);
    }

    public function test_unknown_kid_triggers_exactly_one_refresh_and_then_throws(): void
    {
        $keys = TestKeys::generate();
        $transport = new FakeJwksTransport;
        $transport->document = TestKeys::jwkDocument(TestKeys::kid(), $keys['public']);
        $resolver = new JwksResolver($transport, self::JWKS_URL);

        $resolver->get(TestKeys::kid());

        try {
            $resolver->get('sig_missing');
            $this->fail('Expected a ProtocolViolationException for the unknown kid.');
        } catch (ProtocolViolationException $exception) {
            $this->assertStringContainsString('Unknown signing key', $exception->getMessage());
        }

        $this->assertSame(2, $transport->fetchCount);
    }

    public function test_unknown_kid_succeeds_after_a_key_rotation_refresh(): void
    {
        $keysA = TestKeys::generate();
        $keysB = TestKeys::generate();
        $kidA = TestKeys::kid(1);
        $kidB = TestKeys::kid(2);
        $transport = new FakeJwksTransport;
        $transport->document = TestKeys::jwkDocument($kidA, $keysA['public']);
        $resolver = new JwksResolver($transport, self::JWKS_URL);

        $resolver->get($kidA);

        $transport->document = ['keys' => [
            TestKeys::jwkEntry($kidA, $keysA['public']),
            TestKeys::jwkEntry($kidB, $keysB['public']),
        ]];

        $entry = $resolver->get($kidB);

        $this->assertSame($kidB, $entry->kid);
        $this->assertSame(2, $transport->fetchCount);
    }

    public function test_unknown_kid_without_cache_throws_after_a_single_fetch(): void
    {
        $keys = TestKeys::generate();
        $transport = new FakeJwksTransport;
        $transport->document = TestKeys::jwkDocument(TestKeys::kid(), $keys['public']);
        $resolver = new JwksResolver($transport, self::JWKS_URL);

        $this->expectException(ProtocolViolationException::class);

        $resolver->get('sig_missing');
    }

    public function test_fetch_sends_a_get_request_to_the_jwks_url(): void
    {
        $keys = TestKeys::generate();
        $transport = new FakeJwksTransport;
        $transport->document = TestKeys::jwkDocument(TestKeys::kid(), $keys['public']);
        $resolver = new JwksResolver($transport, self::JWKS_URL);

        $document = $resolver->fetch();

        $this->assertCount(1, $document->keys);

        $lastRequest = $transport->lastRequest;
        $this->assertNotNull($lastRequest);
        $this->assertSame('GET', $lastRequest->method);
        $this->assertSame(self::JWKS_URL, $lastRequest->uri);
    }

    public function test_fetch_failure_surfaces_the_transport_exception(): void
    {
        $transport = new FakeJwksTransport;
        $transport->fail = true;
        $resolver = new JwksResolver($transport, self::JWKS_URL);

        $this->expectException(TransportException::class);

        $resolver->get(TestKeys::kid());
    }

    public function test_non_success_jwks_response_is_mapped_to_an_api_exception(): void
    {
        $transport = new FakeJwksTransport;
        $transport->status = 500;
        $resolver = new JwksResolver($transport, self::JWKS_URL);

        $this->expectException(ServerException::class);

        $resolver->fetch();
    }

    public function test_invalid_jwks_json_is_a_protocol_violation(): void
    {
        $transport = new FakeJwksTransport;
        $transport->rawBody = 'this is not json';
        $resolver = new JwksResolver($transport, self::JWKS_URL);

        $this->expectException(ProtocolViolationException::class);

        $resolver->fetch();
    }

    public function test_missing_keys_field_is_a_protocol_violation(): void
    {
        $transport = new FakeJwksTransport;
        $transport->document = [];
        $resolver = new JwksResolver($transport, self::JWKS_URL);

        $this->expectException(ProtocolViolationException::class);

        $resolver->fetch();
    }

    public function test_callable_transport_is_supported(): void
    {
        $keys = TestKeys::generate();
        $fetches = 0;

        $callable = function (string $url) use (&$fetches, $keys): string {
            $fetches++;

            $this->assertSame(self::JWKS_URL, $url);

            return json_encode(TestKeys::jwkDocument(TestKeys::kid(), $keys['public']), JSON_THROW_ON_ERROR);
        };

        $resolver = new JwksResolver($callable, self::JWKS_URL);
        $entry = $resolver->get(TestKeys::kid());

        $this->assertSame(TestKeys::kid(), $entry->kid);
        $this->assertSame(1, $fetches);
    }

    public function test_failed_refresh_keeps_the_valid_cache(): void
    {
        $keys = TestKeys::generate();
        $transport = new FakeJwksTransport;
        $transport->document = TestKeys::jwkDocument(TestKeys::kid(), $keys['public']);
        $resolver = new JwksResolver($transport, self::JWKS_URL);

        $resolver->get(TestKeys::kid());

        $transport->fail = true;

        try {
            $resolver->get('sig_missing');
            $this->fail('Expected a TransportException for the failed refresh.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(TransportException::class, $exception);
        }

        $transport->fail = false;
        $entry = $resolver->get(TestKeys::kid());

        $this->assertSame(TestKeys::kid(), $entry->kid);
        $this->assertSame(2, $transport->fetchCount);
    }

    public function test_empty_ttl_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new JwksResolver(new FakeJwksTransport, self::JWKS_URL, ttlSeconds: 0);
    }

    public function test_empty_jwks_url_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new JwksResolver(new FakeJwksTransport, '');
    }
}
