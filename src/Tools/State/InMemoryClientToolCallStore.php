<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\State;

/**
 * In-memory ClientToolCallStore for tests, development and single-process
 * runtimes.
 *
 * Records are keyed by (chatId, callId). begin() transitions are atomic
 * within the process, which makes the store safe for a single-threaded
 * worker and re-entrant runner calls; it does not survive a restart and is
 * not shared between processes.
 *
 * records() is a test/observation helper returning the current snapshot.
 */
final class InMemoryClientToolCallStore implements ClientToolCallStore, ToolReplayBarrierStore
{
    /**
     * @var array<string, ToolCallRecord>
     */
    private array $records = [];

    /** @var array<string, true> */
    private array $barriers = [];

    public function markTerminal(string $chatId, string $kind, string $id): void
    {
        $this->barriers[json_encode([$chatId, $kind, $id], JSON_THROW_ON_ERROR)] = true;
    }

    public function isTerminal(string $chatId, string $kind, string $id): bool
    {
        return isset($this->barriers[json_encode([$chatId, $kind, $id], JSON_THROW_ON_ERROR)]);
    }

    public function begin(ToolCallIdentity $identity): BeginResult
    {
        $key = self::key($identity);
        $existing = $this->records[$key] ?? null;

        if ($existing !== null) {
            return new BeginResult(self::alreadyStatus($existing->status), $existing);
        }

        $this->records[$key] = new ToolCallRecord(
            $identity,
            ClientToolCallStatus::Started,
            new \DateTimeImmutable('now'),
        );

        return new BeginResult(ToolCallStatus::Started, null);
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
     * Returns the current records, most recently written first.
     *
     * @return list<ToolCallRecord>
     */
    public function records(): array
    {
        return array_values($this->records);
    }

    private function transition(ToolCallIdentity $identity, ClientToolCallStatus $status): void
    {
        $key = self::key($identity);
        $existing = $this->records[$key] ?? null;

        if ($existing === null) {
            throw new \LogicException('Cannot mark a tool call that was never begun.');
        }

        if ($existing->status === ClientToolCallStatus::Resolved || ($existing->status === ClientToolCallStatus::Ambiguous && $status === ClientToolCallStatus::Submitted)) {
            return;
        }
        $this->records[$key] = new ToolCallRecord(
            $identity,
            $status,
            new \DateTimeImmutable('now'),
            $existing->outputHash,
        );
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
        return $identity->chatId."\n".$identity->callId;
    }
}
