<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Streaming;

/**
 * ATP wire fixtures for the streaming tests.
 *
 * The sequences mirror docs/EXAMPLES.md verbatim: same ids, timestamps and
 * payload values, built as PHP arrays and JSON-encoded with the same key
 * order so the wire bytes match the documented examples.
 */
final class Fixtures
{
    public const CHAT_ID = 'cht_01j5abcdefghjkmnpqrstvwxyz';

    public const TURN_ID = 'cmpl_01j5abcdefghjkmnpqrstuvwxyz';

    public const ORCHESTRATOR_ID = 'ag_01j5abcdefghjkmnpqrstvwxyz';

    public const MEMBER_ID = 'ag_01j5abcdefghjkmnpqrstvwxye';

    /**
     * @return array<string, mixed>
     */
    public static function usage(int $input = 120, int $output = 450, float $cost = 0.42, int $duration = 8123, int $sprite = 0): array
    {
        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_usd' => $cost,
            'duration_ms' => $duration,
            'sprite_ms' => $sprite,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function orchestrator(?string $model = 'anthropic/claude-sonnet-4-5'): array
    {
        return [
            'instance_id' => self::ORCHESTRATOR_ID,
            'name' => 'orchestrator',
            'role' => 'orchestrator',
            'parent_id' => null,
            'model' => $model,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function member(): array
    {
        return [
            'instance_id' => self::MEMBER_ID,
            'name' => 'researcher',
            'role' => 'member',
            'parent_id' => self::ORCHESTRATOR_ID,
            'model' => 'anthropic/claude-haiku-4-5',
        ];
    }

    /**
     * Builds one envelope array.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $agent
     * @return array<string, mixed>
     */
    public static function envelope(int $seq, string $id, string $ts, string $type, array $data, ?array $agent, ?string $turnId = self::TURN_ID, array $extra = []): array
    {
        return array_merge($extra, [
            'v' => 3,
            'id' => $id,
            'seq' => $seq,
            'ts' => $ts,
            'chat_id' => self::CHAT_ID,
            'turn_id' => $turnId,
            'agent' => $agent,
            'type' => $type,
            'data' => $data,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function ping(string $id = 'evt_01j5abcdefghjkmnpqrstvwxy0', string $ts = '2026-08-17T14:00:00Z'): array
    {
        return self::envelope(0, $id, $ts, 'ping', [], null, null);
    }

    /**
     * The EXAMPLES.md "Simple turn" sequence verbatim (seq 1..7).
     *
     * @return list<array<string, mixed>>
     */
    public static function simpleTurn(): array
    {
        $orchestrator = [
            'instance_id' => self::ORCHESTRATOR_ID,
            'name' => 'orchestrator',
            'role' => 'orchestrator',
            'parent_id' => null,
        ];

        return [
            self::envelope(1, 'evt_01j5abcdefghjkmnpqrstvwxy1', '2026-08-17T14:00:00Z', 'turn.started', [
                'turn_id' => self::TURN_ID,
                'input' => "Analizza il traffico dell'ultima settimana",
            ], null),
            self::envelope(2, 'evt_01j5abcdefghjkmnpqrstvwxy2', '2026-08-17T14:00:01Z', 'agent.started', [
                'name' => 'orchestrator',
                'role' => 'orchestrator',
                'model' => 'anthropic/claude-sonnet-4-5',
                'parent_id' => null,
            ], $orchestrator),
            self::envelope(3, 'evt_01j5abcdefghjkmnpqrstvwxy3', '2026-08-17T14:00:01Z', 'agent.plan', [
                'plan' => 'Delego la raccolta dati al researcher e poi sintetizzo.',
            ], $orchestrator),
            self::envelope(4, 'evt_01j5abcdefghjkmnpqrstvwxy4', '2026-08-17T14:00:02Z', 'agent.text.delta', [
                'delta' => "Ecco l'analisi del traffico:",
            ], $orchestrator),
            self::envelope(5, 'evt_01j5abcdefghjkmnpqrstvwxy5', '2026-08-17T14:00:03Z', 'agent.text.done', [
                'text' => "Ecco l'analisi del traffico: 12k visitatori, +8% rispetto alla scorsa settimana.",
            ], $orchestrator),
            self::envelope(6, 'evt_01j5abcdefghjkmnpqrstvwxy6', '2026-08-17T14:00:03Z', 'agent.done', [
                'status' => 'completed',
                'summary' => 'Turno completato',
                'usage' => self::usage(),
                'duration_ms' => 8123,
            ], $orchestrator),
            self::envelope(7, 'evt_01j5abcdefghjkmnpqrstvwxy7', '2026-08-17T14:00:03Z', 'turn.completed', [
                'turn_id' => self::TURN_ID,
                'usage' => self::usage(),
                'cost' => 0.42,
                'chat_usage' => self::usage(),
                'chat_cost' => 0.42,
                'finish_reason' => 'completed',
            ], null),
        ];
    }

    /**
     * The EXAMPLES.md "Second turn, continuous sequence" verbatim (seq 8..10).
     *
     * @return list<array<string, mixed>>
     */
    public static function secondTurn(): array
    {
        $turnId = 'cmpl_01j5abcdefghjkmnpqrstuvwxyA';
        $orchestrator = [
            'instance_id' => 'ag_01j5abcdefghjkmnpqrstvwxyB',
            'name' => 'orchestrator',
            'role' => 'orchestrator',
            'parent_id' => null,
        ];

        return [
            self::envelope(8, 'evt_01j5abcdefghjkmnpqrstvwxy8', '2026-08-17T14:05:00Z', 'turn.started', [
                'turn_id' => $turnId,
                'input' => 'E confronta con il mese scorso',
            ], null, $turnId),
            self::envelope(9, 'evt_01j5abcdefghjkmnpqrstvwxy9', '2026-08-17T14:05:01Z', 'agent.started', [
                'name' => 'orchestrator',
                'role' => 'orchestrator',
                'model' => 'anthropic/claude-sonnet-4-5',
                'parent_id' => null,
            ], $orchestrator, $turnId),
            self::envelope(10, 'evt_01j5abcdefghjkmnpqrstvwxyA', '2026-08-17T14:05:03Z', 'turn.completed', [
                'turn_id' => $turnId,
                'usage' => self::usage(200, 300, 0.5, 12345),
                'cost' => 0.5,
                'chat_usage' => self::usage(320, 750, 0.92, 20468),
                'chat_cost' => 0.92,
                'finish_reason' => 'completed',
            ], null, $turnId),
        ];
    }

    /**
     * The EXAMPLES.md "Delegation" sequence verbatim (seq 11..18).
     *
     * @return list<array<string, mixed>>
     */
    public static function delegation(): array
    {
        $turnId = 'cmpl_01j5abcdefghjkmnpqrstuvwxyC';
        $orchestrator = [
            'instance_id' => 'ag_01j5abcdefghjkmnpqrstvwxyd',
            'name' => 'orchestrator',
            'role' => 'orchestrator',
            'parent_id' => null,
        ];
        $member = [
            'instance_id' => self::MEMBER_ID,
            'name' => 'researcher',
            'role' => 'member',
            'parent_id' => 'ag_01j5abcdefghjkmnpqrstvwxyd',
        ];

        return [
            self::envelope(11, 'evt_01j5abcdefghjkmnpqrstvwxyB', '2026-08-17T14:06:00Z', 'agent.delegated', [
                'child_instance_id' => self::MEMBER_ID,
                'child_name' => 'researcher',
                'mode' => 'foreground',
                'title' => 'Raccogli traffico',
                'task' => 'Raccogli i dati di traffico degli ultimi 7 giorni',
            ], $orchestrator, $turnId),
            self::envelope(12, 'evt_01j5abcdefghjkmnpqrstvwxyC', '2026-08-17T14:06:00Z', 'agent.started', [
                'name' => 'researcher',
                'role' => 'member',
                'model' => 'anthropic/claude-haiku-4-5',
                'parent_id' => 'ag_01j5abcdefghjkmnpqrstvwxyd',
            ], $member, $turnId),
            self::envelope(13, 'evt_01j5abcdefghjkmnpqrstvwxyD', '2026-08-17T14:06:01Z', 'agent.reasoning.delta', [
                'delta' => 'Cerco le fonti sul traffico...',
            ], $member, $turnId),
            self::envelope(14, 'evt_01j5abcdefghjkmnpqrstvwxyE', '2026-08-17T14:06:02Z', 'agent.tool.start', [
                'call_id' => 'call_01j5abcdefghjkmnpqrstvwxyz',
                'tool_name' => 'read_wiki',
                'kind' => 'knowledge',
                'args' => ['page' => 'traffic'],
            ], $member, $turnId),
            self::envelope(15, 'evt_01j5abcdefghjkmnpqrstvwxyF', '2026-08-17T14:06:03Z', 'agent.tool.complete', [
                'call_id' => 'call_01j5abcdefghjkmnpqrstvwxyz',
                'result' => 'Dati estratti dalla wiki',
                'is_error' => false,
                'usage' => self::usage(50, 80, 0.05, 1000),
            ], $member, $turnId),
            self::envelope(16, 'evt_01j5abcdefghjkmnpqrstvwxyG', '2026-08-17T14:06:04Z', 'agent.text.delta', [
                'delta' => '12k visitatori, +8%.',
            ], $member, $turnId),
            self::envelope(17, 'evt_01j5abcdefghjkmnpqrstvwxyH', '2026-08-17T14:06:05Z', 'agent.done', [
                'status' => 'completed',
                'summary' => 'Dati raccolti',
                'usage' => self::usage(50, 80, 0.05, 1000),
                'duration_ms' => 1000,
            ], $member, $turnId),
            self::envelope(18, 'evt_01j5abcdefghjkmnpqrstvwxyI', '2026-08-17T14:06:06Z', 'agent.text.delta', [
                'delta' => 'Sintesi: 12k visitatori, +8% rispetto alla scorsa settimana.',
            ], $orchestrator, $turnId),
        ];
    }

    /**
     * The EXAMPLES.md "Escalation to approval" sequence verbatim (seq 19..22).
     *
     * @return list<array<string, mixed>>
     */
    public static function escalationApproval(): array
    {
        $turnId = 'cmpl_01j5abcdefghjkmnpqrstuvwxyC';
        $orchestrator = [
            'instance_id' => 'ag_01j5abcdefghjkmnpqrstvwxyd',
            'name' => 'orchestrator',
            'role' => 'orchestrator',
            'parent_id' => null,
        ];
        $member = [
            'instance_id' => self::MEMBER_ID,
            'name' => 'researcher',
            'role' => 'member',
            'parent_id' => 'ag_01j5abcdefghjkmnpqrstvwxyd',
        ];

        return [
            self::envelope(19, 'evt_01j5abcdefghjkmnpqrstvwxyJ', '2026-08-17T14:07:00Z', 'agent.escalate', [
                'escalation_id' => 'escal_01j5abcdefghjkmnpqrstvwxyz',
                'agent_id' => self::MEMBER_ID,
                'reason' => 'La scrittura su wiki richiede approvazione',
                'tool_name' => 'write_wiki',
                'args' => ['page' => 'traffic'],
            ], $member, $turnId),
            self::envelope(20, 'evt_01j5abcdefghjkmnpqrstvwxyK', '2026-08-17T14:07:00Z', 'approval.request', [
                'approval_id' => 'appr_01j5abcdefghjkmnpqrstvwxyz',
                'origin_agent_id' => self::MEMBER_ID,
                'tool_name' => 'write_wiki',
                'args' => ['page' => 'traffic'],
                'reason' => 'La scrittura su wiki richiede approvazione',
                'deadline' => '2026-08-17T14:10:00Z',
            ], $orchestrator, $turnId),
            self::envelope(21, 'evt_01j5abcdefghjkmnpqrstvwxyL', '2026-08-17T14:07:30Z', 'approval.response', [
                'approval_id' => 'appr_01j5abcdefghjkmnpqrstvwxyz',
                'approve' => true,
                'reason' => 'Ok',
            ], null, $turnId),
            self::envelope(22, 'evt_01j5abcdefghjkmnpqrstvwxyM', '2026-08-17T14:07:30Z', 'agent.escalation.result', [
                'escalation_id' => 'escal_01j5abcdefghjkmnpqrstvwxyz',
                'approve' => true,
                'reason' => 'Ok',
            ], $orchestrator, $turnId),
        ];
    }

    /**
     * The EXAMPLES.md "Client tool" request/result pair verbatim (seq 23..24).
     *
     * @return list<array<string, mixed>>
     */
    public static function clientTool(): array
    {
        $turnId = 'cmpl_01j5abcdefghjkmnpqrstuvwxyC';
        $member = [
            'instance_id' => self::MEMBER_ID,
            'name' => 'researcher',
            'role' => 'member',
            'parent_id' => 'ag_01j5abcdefghjkmnpqrstvwxyd',
        ];

        return [
            self::envelope(23, 'evt_01j5abcdefghjkmnpqrstvwxyN', '2026-08-17T14:08:00Z', 'agent.tool.request', [
                'call_id' => 'tc_01j5abcdefghjkmnpqrstuvwxyz',
                'tool_name' => 'lookup_order',
                'kind' => 'client',
                'args' => ['order_id' => 'ord_123'],
                'signature' => [
                    'alg' => 'EdDSA',
                    'kid' => 'sig_01j5abcdefghjkmnpqrstvwxyz',
                    'sig' => '4Z7vK1QpR8wXcB2mN6sT9yDfG0hJqLzMxVbNcAsDfGhJkLzXcVbNmQsWtY',
                ],
            ], $member, $turnId),
            self::envelope(24, 'evt_01j5abcdefghjkmnpqrstvwxyO', '2026-08-17T14:08:05Z', 'agent.tool.result', [
                'call_id' => 'tc_01j5abcdefghjkmnpqrstuvwxyz',
                'output' => '{"order_id":"ord_123","total_cents":4900}',
                'is_error' => false,
            ], $member, $turnId),
        ];
    }

    /**
     * One valid envelope sample per known ATP event type, used to prove the
     * decoder produces the right typed class for all 28 types.
     *
     * @return array<string, mixed>
     */
    public static function sample(string $type): array
    {
        $usage = self::usage();
        $orchestrator = self::orchestrator();
        $member = self::member();
        $data = [];
        $agent = null;
        $turnId = self::TURN_ID;

        switch ($type) {
            case 'ping':
                return self::ping('evt_01j5abcdefghjkmnpqrstuvwxy0');
            case 'turn.started':
                $data = ['turn_id' => self::TURN_ID, 'input' => 'Ciao'];
                break;
            case 'turn.completed':
                $data = ['turn_id' => self::TURN_ID, 'usage' => $usage, 'cost' => 0.42, 'chat_usage' => $usage, 'chat_cost' => 0.42, 'finish_reason' => 'completed'];
                break;
            case 'turn.cancelled':
                $data = ['turn_id' => self::TURN_ID, 'reason' => 'user_cancelled', 'usage' => $usage, 'cost' => 0.42, 'chat_usage' => $usage, 'chat_cost' => 0.42];
                break;
            case 'turn.failed':
                $data = ['turn_id' => self::TURN_ID, 'code' => 'provider_error', 'message' => 'Il provider non risponde', 'usage' => $usage, 'cost' => 0.42, 'chat_usage' => $usage, 'chat_cost' => 0.42];
                break;
            case 'turn.steered':
                $data = ['turn_id' => self::TURN_ID, 'text' => 'Preferisci il provider economico'];
                break;
            case 'agent.started':
                $agent = $orchestrator;
                $data = ['name' => 'orchestrator', 'role' => 'orchestrator', 'model' => 'anthropic/claude-sonnet-4-5', 'parent_id' => null];
                break;
            case 'agent.plan':
                $agent = $orchestrator;
                $data = ['plan' => 'Piano di lavoro'];
                break;
            case 'agent.text.delta':
                $agent = $orchestrator;
                $data = ['delta' => 'frammento'];
                break;
            case 'agent.text.done':
                $agent = $orchestrator;
                $data = ['text' => 'Testo finale'];
                break;
            case 'agent.reasoning.delta':
                $agent = $orchestrator;
                $data = ['delta' => 'ragiono...'];
                break;
            case 'agent.reasoning.done':
                $agent = $orchestrator;
                $data = ['text' => 'Ragionamento finale'];
                break;
            case 'agent.delegated':
                $agent = $orchestrator;
                $data = ['child_instance_id' => self::MEMBER_ID, 'child_name' => 'researcher', 'mode' => 'foreground', 'title' => 'Raccogli', 'task' => 'Raccogli i dati'];
                break;
            case 'agent.tool.start':
                $agent = $member;
                $data = ['call_id' => 'call_01j5abcdefghjkmnpqrstuvwxy1', 'tool_name' => 'read_wiki', 'kind' => 'knowledge', 'args' => ['page' => 'traffic']];
                break;
            case 'agent.tool.delta':
                $agent = $member;
                $data = ['call_id' => 'call_01j5abcdefghjkmnpqrstuvwxy1', 'delta' => 'output parziale'];
                break;
            case 'agent.tool.complete':
                $agent = $member;
                $data = ['call_id' => 'call_01j5abcdefghjkmnpqrstuvwxy1', 'result' => 'Risultato', 'is_error' => false, 'usage' => $usage];
                break;
            case 'agent.tool.request':
                $agent = $member;
                $data = [
                    'call_id' => 'tc_01j5abcdefghjkmnpqrstuvwxyz',
                    'tool_name' => 'lookup_order',
                    'kind' => 'client',
                    'args' => ['order_id' => 'ord_123'],
                    'signature' => ['alg' => 'EdDSA', 'kid' => 'sig_01j5abcdefghjkmnpqrstuvwxy1', 'sig' => 'c2lnbmF0dXJl'],
                ];
                break;
            case 'agent.tool.result':
                $agent = $member;
                $data = ['call_id' => 'tc_01j5abcdefghjkmnpqrstuvwxyz', 'output' => '{}', 'is_error' => false];
                break;
            case 'agent.escalate':
                $agent = $member;
                $data = ['escalation_id' => 'escal_01j5abcdefghjkmnpqrstuvwxy1', 'agent_id' => self::MEMBER_ID, 'reason' => 'Serve una decisione', 'tool_name' => 'write_wiki', 'args' => ['page' => 'traffic']];
                break;
            case 'agent.escalation.result':
                $agent = $orchestrator;
                $data = ['escalation_id' => 'escal_01j5abcdefghjkmnpqrstuvwxy1', 'approve' => true, 'reason' => 'Ok'];
                break;
            case 'agent.artifact':
                $agent = $member;
                $data = ['artifact_id' => 'art_01j5abcdefghjkmnpqrstuvwxy1', 'name' => 'report.md', 'media_type' => 'text/markdown', 'size' => 1024];
                break;
            case 'agent.context.compacting':
                $agent = $orchestrator;
                break;
            case 'agent.context.compacted':
                $agent = $orchestrator;
                $data = ['new_context' => 'Contesto riassunto'];
                break;
            case 'agent.done':
                $agent = $orchestrator;
                $data = ['status' => 'completed', 'summary' => 'Fatto', 'usage' => $usage, 'duration_ms' => 8123];
                break;
            case 'agent.failed':
                $agent = $member;
                $data = ['code' => 'tool_error', 'message' => 'Il tool è fallito'];
                break;
            case 'approval.request':
                $agent = $orchestrator;
                $data = ['approval_id' => 'appr_01j5abcdefghjkmnpqrstuvwxy1', 'origin_agent_id' => self::MEMBER_ID, 'tool_name' => 'write_wiki', 'args' => ['page' => 'traffic'], 'reason' => 'Richiede approvazione', 'deadline' => '2026-08-17T14:10:00Z'];
                break;
            case 'approval.response':
                $data = ['approval_id' => 'appr_01j5abcdefghjkmnpqrstuvwxy1', 'approve' => true, 'reason' => 'Ok'];
                break;
            case 'approval.expire':
                $agent = $orchestrator;
                $data = ['approval_id' => 'appr_01j5abcdefghjkmnpqrstuvwxy1'];
                break;
            default:
                throw new \InvalidArgumentException('Unknown sample type: '.$type);
        }

        return self::envelope(1, 'evt_01j5abcdefghjkmnpqrstuvwxy1', '2026-08-17T14:00:00Z', $type, $data, $agent, $turnId);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    public static function resequence(array $events, int $offset): array
    {
        $result = [];

        foreach ($events as $event) {
            $event['seq'] = $event['seq'] + $offset;
            $result[] = $event;
        }

        return $result;
    }
}
