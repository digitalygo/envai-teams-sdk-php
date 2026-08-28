<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Atp;

use Digitalygo\Envai\Streaming\Events\AgentTextDeltaEvent;
use Digitalygo\Envai\Streaming\Events\AgentTextDoneEvent;
use Digitalygo\Envai\Streaming\Events\AtpEvent;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Streaming\Events\TurnCancelledEvent;
use Digitalygo\Envai\Streaming\Events\TurnCompletedEvent;
use Digitalygo\Envai\Streaming\Events\TurnFailedEvent;

/**
 * Shared turn correlation and aggregation pump for ChatTurnRuntime.
 *
 * Consumes events one by one and tracks the state of a single turn: the
 * orchestrator text (the full `agent.text.done` of the orchestrator
 * instance, falling back to the concatenated orchestrator `agent.text.delta`
 * fragments), the first turn terminal event whose `turn_id` matches, and the
 * terminal violation when that terminal payload did not validate.
 *
 * The pump never reads the stream itself: the caller keeps consuming the
 * shared ChatEventStream cursor and feeds every event, matching or not.
 */
final class TurnAwait
{
    /**
     * @var list<string>
     */
    private array $orchestratorDeltas = [];

    private ?string $orchestratorDone = null;

    private TurnCompletedEvent|TurnCancelledEvent|TurnFailedEvent|null $terminal = null;

    private ?ProtocolViolationEvent $terminalViolation = null;

    public function __construct(private readonly string $turnId) {}

    /**
     * Consumes one event and returns true once the first terminal event of
     * the awaited turn has been seen; after that the caller must stop
     * feeding and build the result.
     */
    public function push(AtpEvent $event): bool
    {
        if ($event->envelope->turnId !== $this->turnId) {
            return false;
        }

        $agent = $event->envelope->agent;
        $isOrchestrator = $agent !== null && $agent->role === 'orchestrator';

        if ($event instanceof AgentTextDeltaEvent && $isOrchestrator) {
            $this->orchestratorDeltas[] = $event->delta;
        } elseif ($event instanceof AgentTextDoneEvent && $isOrchestrator) {
            $this->orchestratorDone = $event->text;
        } elseif ($event instanceof TurnCompletedEvent) {
            $this->terminal = $event;

            return true;
        } elseif ($event instanceof TurnCancelledEvent) {
            $this->terminal = $event;

            return true;
        } elseif ($event instanceof TurnFailedEvent) {
            $this->terminal = $event;

            return true;
        } elseif ($event instanceof ProtocolViolationEvent && $this->isTerminalType($event->envelope->type)) {
            $this->terminalViolation = $event;

            return true;
        }

        return false;
    }

    /**
     * Builds the turn result. The orchestrator text prefers the full
     * `agent.text.done` of the orchestrator instance and falls back to the
     * concatenated orchestrator text deltas when no final text arrived.
     */
    public function result(string $chatId, int $lastSeq): TurnResult
    {
        return new TurnResult(
            chatId: $chatId,
            turnId: $this->turnId,
            terminal: $this->terminal,
            orchestratorText: $this->orchestratorDone ?? ($this->orchestratorDeltas === [] ? null : implode('', $this->orchestratorDeltas)),
            lastUsage: $this->terminal?->usage,
            lastSeq: $lastSeq,
            terminalViolation: $this->terminalViolation,
        );
    }

    private function isTerminalType(string $type): bool
    {
        return $type === 'turn.completed' || $type === 'turn.cancelled' || $type === 'turn.failed';
    }
}
