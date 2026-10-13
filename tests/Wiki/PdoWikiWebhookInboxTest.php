<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Tools\Security\JwksResolver;
use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\WebhookStoreException;
use Digitalygo\Envai\Webhooks\WebhookVerificationException;
use Digitalygo\Envai\Webhooks\Wiki\Pdo\PdoWikiWebhookInbox;
use Digitalygo\Envai\Webhooks\Wiki\WikiReceiptOutcome;
use Digitalygo\Envai\Webhooks\Wiki\WikiWebhookEvent;
use Digitalygo\Envai\Webhooks\Wiki\WikiWebhookVerifier;
use PHPUnit\Framework\TestCase;

final class PdoWikiWebhookInboxTest extends TestCase
{
    private \PDO $db;

    private array $fixture;

    private WikiWebhookVerifier $verifier;

    protected function setUp(): void
    {
        $this->db = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('CREATE TABLE envai_wiki_webhook_inbox (deduplication_key TEXT PRIMARY KEY,event_id TEXT NOT NULL,workspace_id TEXT NOT NULL,payload_hash TEXT NOT NULL,event_type TEXT NOT NULL,received_at TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE application_outbox (event_id TEXT PRIMARY KEY, payload TEXT NOT NULL)');
        $this->fixture = WikiContractTest::fixture('webhook-signature');
        $fixture = $this->fixture;
        $this->verifier = new WikiWebhookVerifier(new JwksResolver(static fn (): string => json_encode($fixture['jwks']), 'https://trusted.example/jwks'), json_decode($fixture['raw_body'], true)['workspace_id'], clock: static fn (): int => $fixture['now']);
    }

    private function request(): WebhookRequest
    {
        return new WebhookRequest('POST', '/wiki', ['content-type' => 'application/json', ...$this->fixture['headers']], $this->fixture['raw_body']);
    }

    private static function persist(\PDO $db, WikiWebhookEvent $event): void
    {
        $statement = $db->prepare('INSERT INTO application_outbox VALUES (?, ?)');
        $statement->execute([$event->eventId, $event->payload->toJson()]);
    }

    public function test_receipt_and_durable_work_commit_together_and_retry_is_duplicate(): void
    {
        $inbox = new PdoWikiWebhookInbox($this->db, $this->verifier);
        self::assertSame(WikiReceiptOutcome::Accepted, $inbox->receive($this->request(), 'production', self::persist(...)));
        self::assertSame(WikiReceiptOutcome::Duplicate, $inbox->receive($this->request(), 'production', static function (): void {
            throw new \RuntimeException('Duplicate dispatched');
        }));
        self::assertSame(1, $this->db->query('SELECT COUNT(*) FROM application_outbox')->fetchColumn());
        self::assertSame(1, $this->db->query('SELECT COUNT(*) FROM envai_wiki_webhook_inbox')->fetchColumn());
    }

    public function test_crash_before_commit_leaves_no_stranded_claim_or_partial_job(): void
    {
        $inbox = new PdoWikiWebhookInbox($this->db, $this->verifier);
        try {
            $inbox->receive($this->request(), 'production', static function (\PDO $db, WikiWebhookEvent $event): void {
                self::persist($db, $event);
                throw new \RuntimeException('simulated crash');
            });
            self::fail('Crash should propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('simulated crash', $error->getMessage());
        }
        self::assertSame(0, $this->db->query('SELECT COUNT(*) FROM application_outbox')->fetchColumn());
        self::assertSame(0, $this->db->query('SELECT COUNT(*) FROM envai_wiki_webhook_inbox')->fetchColumn());
        self::assertSame(WikiReceiptOutcome::Accepted, $inbox->receive($this->request(), 'production', self::persist(...)));
    }

    public function test_collision_never_dispatches_and_connection_scopes_are_separate(): void
    {
        $inbox = new PdoWikiWebhookInbox($this->db, $this->verifier);
        $inbox->receive($this->request(), 'production', static function (): void {});
        self::assertSame(WikiReceiptOutcome::Accepted, $inbox->receive($this->request(), 'staging', static function (): void {}));
        $this->db->exec("UPDATE envai_wiki_webhook_inbox SET payload_hash = 'different'");
        self::assertSame(WikiReceiptOutcome::Collision, $inbox->receive($this->request(), 'production', static function (): void {
            throw new \RuntimeException('Collision dispatched');
        }));
    }

    public function test_signature_is_verified_before_database_work(): void
    {
        $this->fixture['raw_body'] .= ' ';
        try {
            (new PdoWikiWebhookInbox($this->db, $this->verifier))->receive($this->request(), 'production', self::persist(...));
            self::fail('Tampered signature accepted.');
        } catch (WebhookVerificationException) {
            self::assertSame(0, $this->db->query('SELECT COUNT(*) FROM envai_wiki_webhook_inbox')->fetchColumn());
        }
    }

    public function test_nested_transaction_cannot_ack_before_commit(): void
    {
        $this->db->beginTransaction();
        $this->expectException(WebhookStoreException::class);
        (new PdoWikiWebhookInbox($this->db, $this->verifier))->receive($this->request(), 'production', self::persist(...));
    }

    public function test_callback_cannot_take_transaction_ownership(): void
    {
        $this->expectException(WebhookStoreException::class);
        (new PdoWikiWebhookInbox($this->db, $this->verifier))->receive($this->request(), 'production', static function (\PDO $db): void {
            $db->rollBack();
        });
    }

    public function test_invalid_scope_and_table_are_rejected(): void
    {
        try {
            (new PdoWikiWebhookInbox($this->db, $this->verifier))->receive($this->request(), '', self::persist(...));
            self::fail('Empty scope accepted.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $this->expectException(\InvalidArgumentException::class);
        new PdoWikiWebhookInbox($this->db, $this->verifier, 'table;drop');
    }

    public function test_unsupported_database_driver_is_rejected(): void
    {
        $database = new class extends \PDO
        {
            public function __construct() {}

            public function getAttribute(int $attribute): mixed
            {
                return 'unsupported';
            }
        };
        $this->expectException(\InvalidArgumentException::class);
        new PdoWikiWebhookInbox($database, $this->verifier);
    }

    public function test_missing_receipt_after_insert_does_not_dispatch(): void
    {
        $this->db->exec('CREATE TRIGGER remove_receipt AFTER INSERT ON envai_wiki_webhook_inbox BEGIN DELETE FROM envai_wiki_webhook_inbox WHERE deduplication_key = NEW.deduplication_key; END');
        $this->expectException(WebhookStoreException::class);
        (new PdoWikiWebhookInbox($this->db, $this->verifier))->receive($this->request(), 'production', self::persist(...));
    }

    public function test_silent_pdo_errors_are_not_accepted(): void
    {
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $this->expectException(\InvalidArgumentException::class);
        new PdoWikiWebhookInbox($this->db, $this->verifier);
    }

    public function test_database_failure_rolls_back_and_is_not_acknowledged(): void
    {
        $this->db->exec('DROP TABLE envai_wiki_webhook_inbox');
        $this->expectException(\PDOException::class);
        (new PdoWikiWebhookInbox($this->db, $this->verifier))->receive($this->request(), 'production', self::persist(...));
    }
}
