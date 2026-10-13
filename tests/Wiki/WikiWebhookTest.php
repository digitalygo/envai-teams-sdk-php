<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Tools\Security\JwksResolver;
use Digitalygo\Envai\Webhooks\Contracts\WebhookDispatcher;
use Digitalygo\Envai\Webhooks\InMemoryWebhookIdempotencyStore;
use Digitalygo\Envai\Webhooks\WebhookManager;
use Digitalygo\Envai\Webhooks\WebhookOutcome;
use Digitalygo\Envai\Webhooks\WebhookPayload;
use Digitalygo\Envai\Webhooks\WebhookReceived;
use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\WebhookValidator;
use Digitalygo\Envai\Webhooks\WebhookVerificationException;
use Digitalygo\Envai\Webhooks\Wiki\WikiFileDeletedEvent;
use Digitalygo\Envai\Webhooks\Wiki\WikiFileProcessedEvent;
use Digitalygo\Envai\Webhooks\Wiki\WikiWebhookEvent;
use Digitalygo\Envai\Webhooks\Wiki\WikiWebhookVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WikiWebhookTest extends TestCase
{
    private function fixture(): array
    {
        return WikiContractTest::fixture('webhook-signature');
    }

    private function verifier(?array $fixture = null, ?int $now = null, ?string $workspace = null): WikiWebhookVerifier
    {
        $fixture ??= $this->fixture();

        return new WikiWebhookVerifier(new JwksResolver(static fn (): string => json_encode($fixture['jwks']), 'https://trusted.envai.example/.well-known/wiki-webhook-jwks.json'), $workspace ?? json_decode($fixture['raw_body'], true)['workspace_id'], clock: static fn (): int => $now ?? $fixture['now']);
    }

    private function request(array $fixture): WebhookRequest
    {
        return new WebhookRequest('POST', '/webhooks/wiki', ['content-type' => 'application/json', ...$fixture['headers']], $fixture['raw_body']);
    }

    private function signed(array $data, int $timestamp, string $kid = 'wiki-test-rfc8032'): array
    {
        $fixture = $this->fixture();
        $fixture['raw_body'] = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $fixture['headers']['Envai-Webhook-Timestamp'] = (string) $timestamp;
        $fixture['headers']['Envai-Webhook-Id'] = $data['event_id'];
        $fixture['headers']['Envai-Webhook-Key-Id'] = $kid;
        $pair = sodium_crypto_sign_seed_keypair(hex2bin('9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60'));
        $input = WikiWebhookVerifier::signingInput($kid, $data['event_id'], (string) $timestamp, $fixture['raw_body']);
        $fixture['headers']['Envai-Webhook-Signature'] = rtrim(strtr(base64_encode(sodium_crypto_sign_detached($input, sodium_crypto_sign_secretkey($pair))), '+/', '-_'), '=');

        return $fixture;
    }

    public function test_cross_language_signature_fixture_verifies_exact_bytes(): void
    {
        $fixture = $this->fixture();
        $request = $this->request($fixture);
        self::assertSame($fixture['signing_input'], WikiWebhookVerifier::signingInput($fixture['headers']['Envai-Webhook-Key-Id'], $fixture['headers']['Envai-Webhook-Id'], $fixture['headers']['Envai-Webhook-Timestamp'], $fixture['raw_body']));
        $result = $this->verifier()->verify($request, (new WebhookValidator)->validate($request));
        self::assertSame('wiki.inbox.file.processed', $result->eventType);
        self::assertSame($fixture['headers']['Envai-Webhook-Id'], $result->eventId);
        self::assertSame('wiki-test-rfc8032', $result->metadata->get('key_id'));
    }

    public static function invalidHeaders(): iterable
    {
        foreach (['Envai-Webhook-Id', 'Envai-Webhook-Key-Id', 'Envai-Webhook-Timestamp', 'Envai-Webhook-Signature'] as $header) {
            yield 'missing '.$header => [$header, null];
            yield 'repeated '.$header => [$header, ['one', 'two']];
            yield 'empty '.$header => [$header, ''];
        }
        yield 'invalid id' => ['Envai-Webhook-Id', 'event id'];
        yield 'invalid kid' => ['Envai-Webhook-Key-Id', '../kid'];
        yield 'unknown kid' => ['Envai-Webhook-Key-Id', 'unknown'];
        yield 'leading zero timestamp' => ['Envai-Webhook-Timestamp', '01791907200'];
        yield 'exponent timestamp' => ['Envai-Webhook-Timestamp', '1e9'];
        yield 'timestamp overflow' => ['Envai-Webhook-Timestamp', str_repeat('9', 22)];
        yield 'invalid signature encoding' => ['Envai-Webhook-Signature', 'bad+signature=='];
        yield 'short signature' => ['Envai-Webhook-Signature', 'AA'];
        yield 'valid-sized wrong signature' => ['Envai-Webhook-Signature', rtrim(strtr(base64_encode(str_repeat("\0", 64)), '+/', '-_'), '=')];
    }

    #[DataProvider('invalidHeaders')]
    public function test_malformed_or_tampered_headers_fail_before_dispatch(string $header, mixed $value): void
    {
        $fixture = $this->fixture();
        if ($value === null) {
            unset($fixture['headers'][$header]);
        } else {
            $fixture['headers'][$header] = $value;
        }
        $request = $this->request($fixture);
        $this->expectException(\Throwable::class);
        $this->verifier()->verify($request, (new WebhookValidator)->validate($request));
    }

    public function test_signature_binds_raw_body_workspace_and_delivery_timestamp(): void
    {
        $original = $this->fixture();
        $cases = [[$original, $original['now'] + 301, null], [$original, $original['now'] - 301, null], [$original, null, 'other-workspace']];
        $tampered = $original;
        $tampered['raw_body'] .= "\n";
        $cases[] = [$tampered, null, null];
        foreach ($cases as [$fixture, $now, $workspace]) {
            try {
                $request = $this->request($fixture);
                $this->verifier(now: $now, workspace: $workspace)->verify($request, (new WebhookValidator)->validate($request));
                self::fail('Tampered event accepted.');
            } catch (WebhookVerificationException) {
                self::assertTrue(true);
            }
        }
        $request = $this->request($original);
        self::assertNotNull($this->verifier(now: $original['now'] + 300)->verify($request, (new WebhookValidator)->validate($request)));
        $this->expectException(WebhookVerificationException::class);
        $request = new WebhookRequest('GET', '/', $original['headers'], $original['raw_body']);
        $this->verifier()->verify($request, WebhookPayload::fromJson($original['raw_body']));
    }

    public function test_invalid_key_metadata_and_encoding_are_rejected(): void
    {
        foreach ([['kty', 'RSA'], ['crv', 'X25519'], ['alg', 'RS256'], ['use', 'enc'], ['x', 'a+'], ['x', 'AA']] as [$field, $value]) {
            $fixture = $this->fixture();
            $fixture['jwks']['keys'][0][$field] = $value;
            $request = $this->request($fixture);
            try {
                $this->verifier($fixture)->verify($request, (new WebhookValidator)->validate($request));
                self::fail('Invalid key accepted.');
            } catch (WebhookVerificationException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_rotated_key_refreshes_once_and_old_event_retries_use_delivery_time(): void
    {
        $fixture = $this->fixture();
        $calls = 0;
        $resolver = new JwksResolver(static function () use (&$calls, $fixture): string {
            $calls++;
            $document = $fixture['jwks'];
            if ($calls > 1) {
                $document['keys'][0]['kid'] = 'rotated';
            }

            return json_encode($document);
        }, 'https://trusted.envai.example/.well-known/wiki-webhook-jwks.json');
        $now = $fixture['now'] + 3600;
        $workspace = json_decode($fixture['raw_body'], true)['workspace_id'];
        $verifier = new WikiWebhookVerifier($resolver, $workspace, clock: static fn (): int => $now);
        $resolver->get('wiki-test-rfc8032');
        $retry = $this->signed(json_decode($fixture['raw_body'], true), $now, 'rotated');
        $request = $this->request($retry);
        self::assertSame($fixture['headers']['Envai-Webhook-Id'], $verifier->verify($request, (new WebhookValidator)->validate($request))->eventId);
        self::assertSame(2, $calls);
        $verifier->verify($request, (new WebhookValidator)->validate($request));
        self::assertSame(2, $calls);
    }

    public function test_manager_deduplicates_only_after_verified_scope_and_detects_collision(): void
    {
        $dispatcher = new class implements WebhookDispatcher
        {
            public array $events = [];

            public function dispatch(WebhookReceived $event): void
            {
                $this->events[] = $event;
            }
        };
        $store = new InMemoryWebhookIdempotencyStore;
        $manager = new WebhookManager(new WebhookValidator, $this->verifier(), $store, $dispatcher, scope: 'connection:workspace');
        $fixture = $this->fixture();
        self::assertSame(WebhookOutcome::Dispatched, $manager->process($this->request($fixture))->outcome);
        self::assertSame(WebhookOutcome::Duplicate, $manager->process($this->request($fixture))->outcome);
        self::assertCount(1, $dispatcher->events);
        $changed = json_decode($fixture['raw_body'], true);
        $changed['source_revision']++;
        self::assertSame(WebhookOutcome::Collision, $manager->process($this->request($this->signed($changed, $fixture['now'])))->outcome);
        $changed['workspace_id'] = 'other-workspace';
        self::assertSame(WebhookOutcome::VerificationFailed, $manager->process($this->request($this->signed($changed, $fixture['now'])))->outcome);
        self::assertCount(1, $store->records());
    }

    public function test_signed_deleted_before_processed_keeps_revision_information(): void
    {
        $deleted = WikiWebhookEvent::fromPayload(WebhookPayload::fromJson(json_encode(WikiContractTest::fixture('file-deleted')['data'])));
        $processed = WikiWebhookEvent::fromPayload(WebhookPayload::fromJson(json_encode(WikiContractTest::fixture('file-processed')['data'])));
        self::assertGreaterThan($processed->sourceRevision, $deleted->sourceRevision);
        self::assertSame($processed->fileId, $deleted->fileId);
        self::assertSame('wiki.outbox.file.deleted', $deleted->eventType);
        self::assertInstanceOf(WikiFileDeletedEvent::class, $deleted);
        self::assertInstanceOf(WikiFileProcessedEvent::class, $processed);
    }

    public static function invalidEvents(): iterable
    {
        yield 'version' => ['file-processed', ['schema_version' => '2']];
        yield 'type' => ['file-processed', ['event_type' => 'wiki.unknown']];
        yield 'identity' => ['file-processed', ['file_id' => 'bad id']];
        yield 'revision' => ['file-processed', ['source_revision' => 0]];
        yield 'availability' => ['file-processed', ['availability' => 'deleted']];
        yield 'terminal mismatch' => ['operation-event', ['status' => 'failed']];
        yield 'phase invalid' => ['operation-event', ['phases' => ['unexpected' => []]]];
    }

    #[DataProvider('invalidEvents')]
    public function test_event_discriminator_validation(string $fixture, array $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        WikiWebhookEvent::fromPayload(WebhookPayload::fromJson(json_encode(array_replace(WikiContractTest::fixture($fixture)['data'], $changes))));
    }

    public function test_invalid_verifier_configuration_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WikiWebhookVerifier(new JwksResolver(static fn (): string => '{}', 'https://envai.example/jwks'), '', 0);
    }
}
