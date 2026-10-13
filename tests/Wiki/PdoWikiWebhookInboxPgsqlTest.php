<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use PHPUnit\Framework\TestCase;

final class PdoWikiWebhookInboxPgsqlTest extends TestCase
{
    public function test_concurrent_deliveries_commit_one_receipt_and_one_durable_job_on_postgresql(): void
    {
        $dsn = getenv('ENVAI_TEST_PGSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('ENVAI_TEST_PGSQL_DSN is required for real PostgreSQL concurrency.');
        }
        $user = getenv('ENVAI_TEST_PGSQL_USER') ?: '';
        $password = getenv('ENVAI_TEST_PGSQL_PASSWORD') ?: '';
        $db = new \PDO($dsn, $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $suffix = bin2hex(random_bytes(6));
        $table = 'wiki_receipts_'.$suffix;
        $jobs = 'wiki_jobs_'.$suffix;
        $db->exec('CREATE TABLE '.$table.' (deduplication_key varchar(64) PRIMARY KEY,event_id varchar(255) NOT NULL,workspace_id varchar(255) NOT NULL,payload_hash varchar(64) NOT NULL,event_type varchar(100) NOT NULL,received_at varchar(32) NOT NULL)');
        $db->exec('CREATE TABLE '.$jobs.' (event_id varchar(255) PRIMARY KEY)');
        $script = tempnam(sys_get_temp_dir(), 'envai-wiki-concurrent');
        $code = <<<'CODE'
<?php
require $argv[1];
$fixture = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$db = new PDO(getenv('ENVAI_TEST_PGSQL_DSN'), getenv('ENVAI_TEST_PGSQL_USER') ?: '', getenv('ENVAI_TEST_PGSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$keys = new Digitalygo\Envai\Tools\Security\JwksResolver(static fn (): string => json_encode($fixture['jwks']), 'https://trusted.example/jwks');
$verifier = new Digitalygo\Envai\Webhooks\Wiki\WikiWebhookVerifier($keys, json_decode($fixture['raw_body'], true)['workspace_id'], clock: static fn (): int => $fixture['now']);
$inbox = new Digitalygo\Envai\Webhooks\Wiki\Pdo\PdoWikiWebhookInbox($db, $verifier, $argv[3]);
$request = new Digitalygo\Envai\Webhooks\WebhookRequest('POST', '/wiki', ['content-type'=>'application/json', ...$fixture['headers']], $fixture['raw_body']);
$jobs = $argv[4];
$result = $inbox->receive($request, 'production', static function (PDO $db, $event) use ($jobs): void {
    usleep(50000);
    $insert = $db->prepare('INSERT INTO '.$jobs.' VALUES (?)');
    $insert->execute([$event->eventId]);
});
echo $result->value;
CODE;
        file_put_contents($script, $code);
        $workers = [];
        try {
            for ($i = 0; $i < 6; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, $script, dirname(__DIR__, 2).'/vendor/autoload.php', dirname(__DIR__).'/fixtures/wiki/webhook-signature.json', $table, $jobs], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
            }
            self::assertSame(1, count(array_filter($results, static fn (string $result): bool => $result === 'accepted')));
            self::assertSame(5, count(array_filter($results, static fn (string $result): bool => $result === 'duplicate')));
            self::assertSame(1, $db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
            self::assertSame(1, $db->query('SELECT COUNT(*) FROM '.$jobs)->fetchColumn());
        } finally {
            unlink($script);
            $db->exec('DROP TABLE '.$jobs);
            $db->exec('DROP TABLE '.$table);
        }
    }
}
