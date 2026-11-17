<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Http\HttpResponse;

/** Canonical payloads shared by the chat cron, project skill and cleanup tests. */
final class ManagementFixtures
{
    public const WORKSPACE = 'wsp_01j5abcdefghjkmnpqrstvwxyz';

    public const CHAT = 'cht_01j5abcdefghjkmnpqrstvwxyz';

    public const CRON = 'cron_01j5abcdefghjkmnpqrstvwxyz';

    public const PROJECT = 'prj_01j5abcdefghjkmnpqrstvwxyz';

    public const SKILL = 'psk_01j5abcdefghjkmnpqrstvwxyz';

    public const TEAM = 'team_01j5abcdefghjkmnpqrstvwxyz';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function chatCron(array $overrides = []): array
    {
        return array_merge([
            'id' => self::CRON,
            'workspace_id' => self::WORKSPACE,
            'origin_chat_id' => self::CHAT,
            'team_id' => self::TEAM,
            'target_mode' => 'same_chat',
            'prompt' => 'Summarize the last week of traffic',
            'interval_minutes' => 15,
            'daily_at' => null,
            'timezone' => 'UTC',
            'start_at' => null,
            'repeat_count' => null,
            'until' => null,
            'status' => 'active',
            'scheduled_count' => 0,
            'delivery_count' => 0,
            'next_due_at' => '2026-11-11T09:15:00+00:00',
            'max_until' => '2026-12-10T09:00:00+00:00',
            'created_at' => '2026-11-10T09:00:00+00:00',
            'updated_at' => '2026-11-10T09:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function projectSkill(array $overrides = []): array
    {
        return array_merge([
            'id' => self::SKILL,
            'project_id' => self::PROJECT,
            'created_by_user_id' => null,
            'name' => 'incident_response',
            'description' => 'Safe incident handling',
            'content' => "# Incident response\n\nEscalate after triage.\n",
            'created_at' => '2026-11-10T09:00:00+00:00',
            'updated_at' => '2026-11-10T09:00:00+00:00',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function cleanup(array $overrides = []): array
    {
        return array_merge([
            'resource_type' => 'workspace',
            'resource_id' => self::WORKSPACE,
            'cleanup' => [[
                'id' => 'cln_01j5abcdefghjkmnpqrstvwxyz',
                'kind' => 'workspace',
                'status' => 'pending',
                'attempts' => 0,
                'deadline_at' => null,
                'started_at' => null,
                'finished_at' => null,
                'last_error_code' => null,
            ]],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function chat(array $overrides = []): array
    {
        return array_merge([
            'id' => self::CHAT,
            'workspace_id' => self::WORKSPACE,
            'project_id' => self::PROJECT,
            'created_by_user_id' => null,
            'team_id' => self::TEAM,
            'title' => null,
            'created_at' => '2026-11-10T09:00:00+00:00',
            'updated_at' => '2026-11-10T09:00:00+00:00',
        ], $overrides);
    }

    /** @param array<string, mixed> $data */
    public static function envelope(array $data, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, ['content-type' => ['application/json']], json_encode(['data' => $data], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function collection(array $items): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => ['application/json']], json_encode(['data' => $items], JSON_THROW_ON_ERROR));
    }

    public static function noContent(): HttpResponse
    {
        return new HttpResponse(204, [], null);
    }

    public static function error(int $status, string $type, ?string $code, string $message, mixed $details = null): HttpResponse
    {
        $error = ['type' => $type, 'message' => $message, 'details' => $details];

        if ($code !== null) {
            $error['code'] = $code;
        }

        return new HttpResponse($status, ['content-type' => ['application/json']], json_encode(['error' => $error], JSON_THROW_ON_ERROR));
    }
}
