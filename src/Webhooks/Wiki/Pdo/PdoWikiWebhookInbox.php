<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks\Wiki\Pdo;

use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\WebhookStoreException;
use Digitalygo\Envai\Webhooks\WebhookValidator;
use Digitalygo\Envai\Webhooks\Wiki\WikiReceiptOutcome;
use Digitalygo\Envai\Webhooks\Wiki\WikiWebhookEvent;
use Digitalygo\Envai\Webhooks\Wiki\WikiWebhookVerifier;

final readonly class PdoWikiWebhookInbox
{
    private string $driver;

    public function __construct(private \PDO $database, private WikiWebhookVerifier $verifier, private string $table = 'envai_wiki_webhook_inbox', private WebhookValidator $validator = new WebhookValidator)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table) !== 1) {
            throw new \InvalidArgumentException('Invalid wiki webhook inbox table.');
        }
        $driver = $database->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (! is_string($driver) || ! in_array($driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new \InvalidArgumentException('Wiki inbox requires SQLite, PostgreSQL or MySQL.');
        }
        if ($database->getAttribute(\PDO::ATTR_ERRMODE) !== \PDO::ERRMODE_EXCEPTION) {
            throw new \InvalidArgumentException('Wiki inbox requires PDO exception error mode.');
        }
        $this->driver = $driver;
    }

    /** @param \Closure(\PDO, WikiWebhookEvent): void $persistWork */
    public function receive(WebhookRequest $request, string $connectionScope, \Closure $persistWork): WikiReceiptOutcome
    {
        if ($connectionScope === '' || preg_match('/[\x00-\x1f\x7f]/', $connectionScope) === 1) {
            throw new \InvalidArgumentException('A stable connection scope is required.');
        }
        $payload = $this->validator->validate($request);
        $verified = $this->verifier->verify($request, $payload);
        $event = WikiWebhookEvent::fromPayload($payload);
        if ($this->database->inTransaction()) {
            throw new WebhookStoreException('Wiki inbox must own its transaction so acknowledgment follows durable commit.');
        }
        $identity = hash('sha256', $connectionScope."\0".$event->workspaceId."\0".$verified->eventId);
        $hash = hash('sha256', $request->rawBody);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->database->beginTransaction();
        try {
            $columns = '(deduplication_key, event_id, workspace_id, payload_hash, event_type, received_at) VALUES (:identity, :event, :workspace, :hash, :type, :received)';
            $sql = $this->driver === 'mysql'
                ? 'INSERT IGNORE INTO '.$this->table.' '.$columns
                : 'INSERT INTO '.$this->table.' '.$columns.' ON CONFLICT (deduplication_key) DO NOTHING';
            $insert = $this->database->prepare($sql);
            $insert->execute(['identity' => $identity, 'event' => $event->eventId, 'workspace' => $event->workspaceId, 'hash' => $hash, 'type' => $event->eventType, 'received' => $now]);
            $inserted = $insert->rowCount() === 1;
            $select = $this->database->prepare('SELECT payload_hash FROM '.$this->table.' WHERE deduplication_key = :identity'.($this->driver === 'sqlite' ? '' : ' FOR UPDATE'));
            $select->execute(['identity' => $identity]);
            $storedHash = $select->fetchColumn();
            if (! is_string($storedHash)) {
                throw new WebhookStoreException('Wiki receipt was not durably inserted or readable.');
            }
            if (! hash_equals($storedHash, $hash)) {
                $this->database->rollBack();

                return WikiReceiptOutcome::Collision;
            }
            if ($inserted) {
                $persistWork($this->database, $event);
            }
            if (! $this->database->inTransaction()) {
                throw new WebhookStoreException('The durable work callback must not commit or roll back the inbox transaction.');
            }
            $this->database->commit();

            return $inserted ? WikiReceiptOutcome::Accepted : WikiReceiptOutcome::Duplicate;
        } catch (\Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }
}
