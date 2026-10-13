<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Tests\Wiki\WikiContractTest;
use Digitalygo\Envai\Tools\Security\JwksResolver;
use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\Wiki\Pdo\PdoWikiWebhookInbox;
use Digitalygo\Envai\Webhooks\Wiki\WikiReceiptOutcome;
use Digitalygo\Envai\Webhooks\Wiki\WikiWebhookVerifier;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Support\Facades\Schema;

final class EnvaiWikiInboxMigrationTest extends IntegrationTestCase
{
    public function test_migration_and_laravel_pdo_intake_are_atomic_and_recoverable(): void
    {
        $app = new Container;
        $app->instance('config', new Repository(['database' => ['default' => 'default', 'connections' => ['default' => ['driver' => 'sqlite', 'database' => ':memory:']]]]));
        (new DatabaseServiceProvider($app))->register();
        Schema::clearResolvedInstances();
        Schema::setFacadeApplication($app);
        $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_13_000000_create_envai_wiki_webhook_inbox.php';
        $migration->up();
        self::assertSame(['deduplication_key', 'event_id', 'workspace_id', 'payload_hash', 'event_type', 'received_at'], Schema::getColumnListing('envai_wiki_webhook_inbox'));
        $pdo = $app->make('db')->connection()->getPdo();
        $pdo->exec('CREATE TABLE jobs (event_id TEXT PRIMARY KEY)');
        $fixture = WikiContractTest::fixture('webhook-signature');
        $keys = new JwksResolver(static fn (): string => json_encode($fixture['jwks']), 'https://trusted.example/jwks');
        $verifier = new WikiWebhookVerifier($keys, json_decode($fixture['raw_body'], true)['workspace_id'], clock: static fn (): int => $fixture['now']);
        $inbox = new PdoWikiWebhookInbox($pdo, $verifier);
        $request = new WebhookRequest('POST', '/wiki', ['content-type' => 'application/json', ...$fixture['headers']], $fixture['raw_body']);
        $work = static function (\PDO $pdo, $event): void {
            $insert = $pdo->prepare('INSERT INTO jobs VALUES (?)');
            $insert->execute([$event->eventId]);
        };
        self::assertSame(WikiReceiptOutcome::Accepted, $inbox->receive($request, 'production', $work));
        self::assertSame(WikiReceiptOutcome::Duplicate, $inbox->receive($request, 'production', $work));
        self::assertSame(1, $pdo->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
        $migration->down();
        self::assertFalse(Schema::hasTable('envai_wiki_webhook_inbox'));
        Schema::clearResolvedInstances();
        Schema::setFacadeApplication(null);
    }
}
