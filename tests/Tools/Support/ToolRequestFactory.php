<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Support;

use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Events\AgentToolRequestEvent;
use Digitalygo\Envai\Streaming\Events\EventEnvelope;
use Digitalygo\Envai\Streaming\Events\ToolKind;
use Digitalygo\Envai\Streaming\Events\ToolSignature;

/**
 * Builds `agent.tool.request` events for the runtime tests.
 */
final class ToolRequestFactory
{
    public static function create(
        string $callId,
        string $toolName,
        JsonObject $args,
        ToolSignature $signature,
        string $chatId = 'cht_test_1',
        ?string $turnId = 'cmpl_test_1',
    ): AgentToolRequestEvent {
        $envelope = new EventEnvelope(
            v: 2,
            id: 'evt_'.uniqid('', true),
            seq: 1,
            ts: '2026-08-17T14:00:00Z',
            chatId: $chatId,
            turnId: $turnId,
            agent: null,
            type: 'agent.tool.request',
        );

        return new AgentToolRequestEvent($envelope, $callId, $toolName, ToolKind::Client, $args, $signature);
    }

    /**
     * Builds an event signed over the canonical payload of the given args.
     *
     * @param  array<string, mixed>  $args
     * @param  non-empty-string  $secretKey
     */
    public static function signed(
        string $callId,
        string $toolName,
        array $args,
        string $secretKey,
        string $kid,
        ?string $turnId = 'cmpl_test_1',
    ): AgentToolRequestEvent {
        return self::create(
            $callId,
            $toolName,
            new JsonObject($args),
            new ToolSignature('EdDSA', $kid, TestKeys::sign($callId, $toolName, $args, $secretKey)),
            turnId: $turnId,
        );
    }
}
