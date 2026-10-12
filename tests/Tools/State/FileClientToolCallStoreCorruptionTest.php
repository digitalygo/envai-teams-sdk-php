<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\State;

use Digitalygo\Envai\Exceptions\FileException;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
use Digitalygo\Envai\Tools\Execution\ClientToolRunner;
use Digitalygo\Envai\Tools\Execution\ToolRunOutcome;
use Digitalygo\Envai\Tools\State\ClientToolCallStatus;
use Digitalygo\Envai\Tools\State\FileClientToolCallStore;
use Digitalygo\Envai\Tools\State\ToolCallIdentity;
use Digitalygo\Envai\Tools\State\ToolCallStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FileClientToolCallStoreCorruptionTest extends TestCase
{
    private const KEY = "cht_test_1\ncall_01";

    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/atp-tool-corruption-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        $this->path = $this->directory.'/calls.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $path) {
            unlink($path);
        }

        rmdir($this->directory);
    }

    #[DataProvider('corruptOperations')]
    public function test_every_operation_rejects_corruption_without_changing_the_file(string $operation, string $json): void
    {
        file_put_contents($this->path, $json);
        $store = new FileClientToolCallStore($this->path);
        $identity = new ToolCallIdentity('cht_test_1', 'call_01', 'echo');

        try {
            match ($operation) {
                'begin' => $store->begin($identity),
                'begin-new' => $store->begin(new ToolCallIdentity('cht_test_1', 'call_new', 'echo')),
                'markSubmitted' => $store->markSubmitted($identity),
                'markResolved' => $store->markResolved($identity),
                'markAmbiguous' => $store->markAmbiguous($identity),
                'records' => $store->records(),
                default => throw new \LogicException('Unknown test operation.'),
            };
            $this->fail('Corrupt state must throw FileException before an operation can use or rewrite it.');
        } catch (FileException) {
            $this->assertSame($json, file_get_contents($this->path));
            $this->assertSame([], glob($this->path.'.tmp.*'));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function corruptOperations(): iterable
    {
        foreach (self::corruptFiles() as $case => $json) {
            foreach (['begin', 'begin-new', 'markSubmitted', 'markResolved', 'markAmbiguous', 'records'] as $operation) {
                yield $case.' / '.$operation => [$operation, $json];
            }
        }
    }

    /**
     * @return iterable<string, string>
     */
    private static function corruptFiles(): iterable
    {
        yield 'empty file' => '';
        yield 'whitespace file' => " \n\t";
        yield 'invalid JSON' => '{';

        foreach ([null, false, 1, 'calls', [self::validRow()]] as $index => $calls) {
            yield 'invalid calls container '.$index => json_encode(['format' => 1, 'calls' => $calls], JSON_THROW_ON_ERROR);
        }

        foreach ([null, false, true, 0, 1.5, 'resolved', [], ['resolved']] as $index => $row) {
            yield 'invalid row '.$index => self::state($row);
        }

        foreach (['chat_id', 'call_id', 'tool_name', 'status'] as $field) {
            $row = self::validRow();
            unset($row[$field]);
            yield 'missing '.$field => self::state($row);

            foreach ([null, false, 1, [], ''] as $index => $value) {
                $row = self::validRow();
                $row[$field] = $value;
                yield 'invalid '.$field.' '.$index => self::state($row);
            }
        }

        foreach (['turn_id', 'output_hash', 'updated_at'] as $field) {
            foreach ([false, 1, [], ['value' => 'invalid']] as $index => $value) {
                $row = self::validRow();
                $row[$field] = $value;
                yield 'invalid optional '.$field.' '.$index => self::state($row);
            }
        }

        foreach (['', 'not a timestamp', '2026-02-31T12:00:00+00:00'] as $index => $value) {
            $row = self::validRow();
            $row['updated_at'] = $value;
            yield 'invalid timestamp '.$index => self::state($row);
        }

        $row = self::validRow();
        $row['status'] = 'unknown';
        yield 'unknown status' => self::state($row);

        foreach (['chat_id', 'call_id'] as $field) {
            $row = self::validRow();
            $row[$field] = 'different';
            yield 'mismatched '.$field => self::state($row);
        }

        yield 'invalid other row' => json_encode([
            'format' => 1,
            'calls' => [self::KEY => self::validRow(), "other_chat\nother_call" => null],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    public function test_completed_call_with_null_row_is_not_executed_or_submitted_again(): void
    {
        $tool = new FlexibleTool;
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );
        $store = new FileClientToolCallStore($this->path);
        $runner = new ClientToolRunner($harness->registry, $harness->factory, $harness->verifier, $store, $harness->sender);
        $event = $harness->event('call_01', 'echo');

        $result = $runner->handle($event, 'cht_test_1');
        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertSame(ClientToolCallStatus::Resolved, $store->records()[0]->status);
        $this->assertSame(1, $tool->invocations);
        $this->assertSame(1, $harness->sender->attempts);

        // Preserve the deduplication key but replace its completed row with
        // null, as in a structurally damaged local persistence file.
        $json = self::state(null);
        file_put_contents($this->path, $json);
        $tool->invocations = 0;
        $harness->sender->attempts = 0;
        $harness->sender->submissions = [];
        $restarted = new ClientToolRunner(
            $harness->registry,
            $harness->factory,
            $harness->verifier,
            new FileClientToolCallStore($this->path),
            $harness->sender,
        );

        $this->expectException(FileException::class);

        try {
            $restarted->handle($event, 'cht_test_1');
        } finally {
            $this->assertSame(0, $tool->invocations);
            $this->assertSame(0, $harness->sender->attempts);
            $this->assertSame([], $harness->sender->submissions);
            $this->assertSame($json, file_get_contents($this->path));
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    #[DataProvider('legacyRows')]
    public function test_valid_format_one_rows_remain_readable_and_replay_safe(array $row, ToolCallStatus $expected): void
    {
        file_put_contents($this->path, self::state($row));
        $store = new FileClientToolCallStore($this->path);
        $identity = new ToolCallIdentity('cht_test_1', 'call_01', 'echo');

        $this->assertCount(1, $store->records());
        $this->assertSame($expected, $store->begin($identity)->status);
        $store->markSubmitted($identity);
        $store->markResolved($identity);
        $this->assertSame(ToolCallStatus::AlreadyResolved, (new FileClientToolCallStore($this->path))->begin($identity)->status);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, ToolCallStatus}>
     */
    public static function legacyRows(): iterable
    {
        foreach (ClientToolCallStatus::cases() as $status) {
            $row = self::validRow();
            $row['status'] = $status->value;
            $expected = match ($status) {
                ClientToolCallStatus::Started, ClientToolCallStatus::Submitted => ToolCallStatus::AlreadyRunning,
                ClientToolCallStatus::Resolved => ToolCallStatus::AlreadyResolved,
                ClientToolCallStatus::Ambiguous => ToolCallStatus::AlreadyAmbiguous,
            };
            yield $status->value => [$row, $expected];

            unset($row['turn_id'], $row['output_hash'], $row['updated_at']);
            yield $status->value.' without optional fields' => [$row, $expected];

            $row['turn_id'] = null;
            $row['output_hash'] = null;
            $row['updated_at'] = null;
            yield $status->value.' with null optional fields' => [$row, $expected];
        }
    }

    #[DataProvider('emptyCollections')]
    public function test_valid_empty_legacy_collections_and_missing_keys_can_start(string $json): void
    {
        file_put_contents($this->path, $json);
        $store = new FileClientToolCallStore($this->path);
        $this->assertSame([], $store->records());
        $this->assertSame(ToolCallStatus::Started, $store->begin(new ToolCallIdentity('cht_test_1', 'call_01', 'echo'))->status);
        $this->assertSame(ToolCallStatus::Started, $store->begin(new ToolCallIdentity('cht_test_1', 'call_02', 'echo'))->status);
        $this->assertCount(2, $store->records());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyCollections(): iterable
    {
        yield 'object' => ['{"format":1,"calls":{}}'];
        yield 'legacy array' => ['{"format":1,"calls":[]}'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validRow(): array
    {
        return [
            'chat_id' => 'cht_test_1',
            'call_id' => 'call_01',
            'tool_name' => 'echo',
            'turn_id' => 'cmpl_test_1',
            'status' => 'resolved',
            'updated_at' => '2026-10-12T22:00:00.123456+00:00',
            'output_hash' => null,
        ];
    }

    private static function state(mixed $row): string
    {
        return json_encode(['format' => 1, 'calls' => [self::KEY => $row]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
    }
}
