<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\State;

use Digitalygo\Envai\Exceptions\FileException;

/**
 * File-backed ClientToolCallStore.
 *
 * The state lives in a versioned JSON file: `{"format": 1, "calls": {...}}`
 * keyed by (chatId, callId). Every state transition runs under an exclusive
 * flock on a dedicated `<path>.lock` file for the whole read-modify-write
 * cycle, and the payload is written atomically through a temporary file plus
 * rename, so a crash never corrupts the last valid state. The state file is
 * created with restrictive permissions (0600) and never stores credentials.
 *
 * Suitable for single-host runtimes, CI and development; multi-node
 * deployments should use a shared store (database, Redis, ...). A call whose
 * record is missing when a mark_* method is called indicates a programming
 * error and throws.
 */
final class FileClientToolCallStore implements ClientToolCallStore
{
    private const FORMAT = 1;

    private const SEPARATOR = "\n";

    public function __construct(
        private readonly string $path,
    ) {
        if ($path === '') {
            throw new \InvalidArgumentException('State file path must not be empty.');
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw FileException::local(sprintf('State file directory "%s" does not exist.', $directory));
        }
    }

    public function begin(ToolCallIdentity $identity): BeginResult
    {
        $result = $this->transact(static function (array $calls) use ($identity): array {
            $key = self::key($identity);
            $row = $calls[$key] ?? null;

            if ($row !== null) {
                return [$calls, new BeginResult(self::alreadyStatus(self::statusOf($row)), self::recordFromRow($row))];
            }

            $calls[$key] = self::row($identity, ClientToolCallStatus::Started, self::nowString());

            return [$calls, new BeginResult(ToolCallStatus::Started, null)];
        });

        return $result;
    }

    public function markSubmitted(ToolCallIdentity $identity): void
    {
        $this->transition($identity, ClientToolCallStatus::Submitted);
    }

    public function markResolved(ToolCallIdentity $identity): void
    {
        $this->transition($identity, ClientToolCallStatus::Resolved);
    }

    public function markAmbiguous(ToolCallIdentity $identity): void
    {
        $this->transition($identity, ClientToolCallStatus::Ambiguous);
    }

    /**
     * Returns the persisted records, newest first. Observation helper.
     *
     * @return list<ToolCallRecord>
     */
    public function records(): array
    {
        $lock = $this->lockHandle();

        try {
            if (! flock($lock, LOCK_SH)) {
                throw FileException::local(sprintf('Cannot acquire a shared lock on "%s".', $this->lockPath()));
            }

            $rows = $this->readCalls();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return array_values(array_map(self::recordFromRow(...), $rows));
    }

    private function transition(ToolCallIdentity $identity, ClientToolCallStatus $status): void
    {
        $this->transact(static function (array $calls) use ($identity, $status): array {
            $key = self::key($identity);
            $row = $calls[$key] ?? null;

            if ($row === null) {
                throw new \LogicException('Cannot mark a tool call that was never begun.');
            }

            $row['status'] = $status->value;
            $row['updated_at'] = self::nowString();
            $calls[$key] = $row;

            return [$calls, null];
        });
    }

    /**
     * Runs an atomic read-modify-write under the exclusive lock. The
     * operation receives the decoded calls and returns
     * [calls, result]; the updated calls are persisted before the result
     * is handed back.
     *
     * @template T
     *
     * @param  \Closure(array<string, array<string, mixed>>): array{0: array<string, array<string, mixed>>, 1: T}  $operation
     * @return T
     */
    private function transact(\Closure $operation): mixed
    {
        $lock = $this->lockHandle();

        try {
            if (! flock($lock, LOCK_EX)) {
                throw FileException::local(sprintf('Cannot acquire an exclusive lock on "%s".', $this->lockPath()));
            }

            [$calls, $result] = $operation($this->readCalls());
            $this->writeCalls($calls);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readCalls(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $json = file_get_contents($this->path);

        if ($json === false) {
            throw FileException::local(sprintf('Cannot read state file "%s".', $this->path));
        }

        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw FileException::local(
                sprintf('State file "%s" contains invalid JSON: %s', $this->path, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (! is_array($decoded) || ($decoded['format'] ?? null) !== self::FORMAT) {
            throw FileException::local(sprintf('State file "%s" has an unsupported format.', $this->path));
        }

        $calls = $decoded['calls'] ?? null;

        if (! is_array($calls)) {
            throw FileException::local(sprintf('State file "%s" has an unsupported format.', $this->path));
        }

        /** @var array<string, array<string, mixed>> $calls */
        return $calls;
    }

    /**
     * @param  array<string, array<string, mixed>>  $calls
     */
    private function writeCalls(array $calls): void
    {
        $payload = json_encode(
            ['format' => self::FORMAT, 'calls' => $calls],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        $temporary = $this->path.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(4));

        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            @unlink($temporary);
            throw FileException::local(sprintf('Cannot write temporary state file "%s".', $temporary));
        }

        @chmod($temporary, 0600);

        if (! rename($temporary, $this->path)) {
            @unlink($temporary);
            throw FileException::local(sprintf('Cannot atomically replace state file "%s".', $this->path));
        }
    }

    /**
     * Opens (creating if needed) the dedicated lock file.
     *
     * @return resource
     */
    private function lockHandle()
    {
        $handle = fopen($this->lockPath(), 'c');

        if ($handle === false) {
            throw FileException::local(sprintf('Cannot open lock file "%s".', $this->lockPath()));
        }

        @chmod($this->lockPath(), 0600);

        return $handle;
    }

    private function lockPath(): string
    {
        return $this->path.'.lock';
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(ToolCallIdentity $identity, ClientToolCallStatus $status, string $updatedAt): array
    {
        return [
            'chat_id' => $identity->chatId,
            'call_id' => $identity->callId,
            'tool_name' => $identity->toolName,
            'turn_id' => $identity->turnId,
            'status' => $status->value,
            'updated_at' => $updatedAt,
            'output_hash' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function recordFromRow(array $row): ToolCallRecord
    {
        $identity = new ToolCallIdentity(
            chatId: self::stringField($row, 'chat_id'),
            callId: self::stringField($row, 'call_id'),
            toolName: self::stringField($row, 'tool_name'),
            turnId: isset($row['turn_id']) && is_string($row['turn_id']) && $row['turn_id'] !== '' ? $row['turn_id'] : null,
        );

        $status = ClientToolCallStatus::tryFrom(self::stringField($row, 'status'));

        if ($status === null) {
            throw FileException::local('State file contains an unknown tool call status.');
        }

        $updatedAt = self::parseTimestamp($row['updated_at'] ?? null);
        $outputHash = isset($row['output_hash']) && is_string($row['output_hash']) ? $row['output_hash'] : null;

        return new ToolCallRecord($identity, $status, $updatedAt, $outputHash);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function statusOf(array $row): ClientToolCallStatus
    {
        $status = ClientToolCallStatus::tryFrom(self::stringField($row, 'status'));

        if ($status === null) {
            throw FileException::local('State file contains an unknown tool call status.');
        }

        return $status;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function stringField(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        if (! is_string($value) || $value === '') {
            throw FileException::local(sprintf('State file contains an invalid "%s" field.', $field));
        }

        return $value;
    }

    private static function parseTimestamp(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw FileException::local('State file contains an invalid updated_at timestamp.', previous: $exception);
        }
    }

    private static function alreadyStatus(ClientToolCallStatus $status): ToolCallStatus
    {
        return match ($status) {
            ClientToolCallStatus::Started, ClientToolCallStatus::Submitted => ToolCallStatus::AlreadyRunning,
            ClientToolCallStatus::Resolved => ToolCallStatus::AlreadyResolved,
            ClientToolCallStatus::Ambiguous => ToolCallStatus::AlreadyAmbiguous,
        };
    }

    private static function key(ToolCallIdentity $identity): string
    {
        return $identity->chatId.self::SEPARATOR.$identity->callId;
    }

    private static function nowString(): string
    {
        return (new \DateTimeImmutable('now'))->format('Y-m-d\TH:i:s.uP');
    }
}
