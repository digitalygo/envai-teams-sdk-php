<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Fakes;

use Digitalygo\Envai\Exceptions\ApiException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Exceptions\ValidationException;
use Digitalygo\Envai\Models\Common\ApiError;
use Digitalygo\Envai\Sync\Reconciler\RemoteWriter;

/**
 * Scriptable fake of the RemoteWriter backed by an in-memory "server",
 * used by the reconciler tests. Every mutation is recorded in $calls in
 * execution order, so tests can assert the exact ordering of the apply.
 *
 * Failure scripting is keyed per method + resource identity, for example
 * `createTool:query_db` or `deleteTeam:team_x`; the scripted Throwable is
 * thrown instead of performing the mutation. readRemote consults the
 * server, so post-ambiguity reconciliation observes the state left by the
 * scripted failure.
 */
final class FakeRemoteWriter implements RemoteWriter
{
    /**
     * In-memory server state keyed `tool:<name>`, `agent:<id>` and
     * `team:<id>`.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $server = [];

    /**
     * Recorded mutation calls in execution order.
     *
     * @var list<array{method: string, target: string, payload: array<string, mixed>|null}>
     */
    public array $calls = [];

    /**
     * @var array<string, \Throwable>
     */
    private array $failures = [];

    public function createTool(array $payload): array
    {
        return $this->mutate('createTool', 'tool:'.($payload['name'] ?? '?'), $payload, 'twl');
    }

    public function updateTool(string $name, array $payload): array
    {
        return $this->mutate('updateTool', 'tool:'.$name, $payload, 'twl');
    }

    public function deleteTool(string $name): void
    {
        $this->mutate('deleteTool', 'tool:'.$name, null, 'twl');
    }

    public function createAgent(array $payload): array
    {
        return $this->mutate('createAgent', 'agent:'.($payload['name'] ?? '?'), $payload, 'agt');
    }

    public function updateAgent(string $agentId, array $payload): array
    {
        return $this->mutate('updateAgent', 'agent:'.$agentId, $payload, 'agt');
    }

    public function deleteAgent(string $agentId): void
    {
        $this->mutate('deleteAgent', 'agent:'.$agentId, null, 'agt');
    }

    public function createTeam(array $payload): array
    {
        return $this->mutate('createTeam', 'team:'.($payload['name'] ?? '?'), $payload, 'team');
    }

    public function updateTeam(string $teamId, array $payload): array
    {
        return $this->mutate('updateTeam', 'team:'.$teamId, $payload, 'team');
    }

    public function deleteTeam(string $teamId): void
    {
        $this->mutate('deleteTeam', 'team:'.$teamId, null, 'team');
    }

    public function readRemote(string $type, string $nameOrId): ?array
    {
        $prefix = match ($type) {
            'tool' => 'tool:',
            'agent' => 'agent:',
            'team' => 'team:',
            default => 'tool:',
        };

        return $this->server[$prefix.$nameOrId] ?? null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function seed(string $key, array $payload): void
    {
        $this->server[$key] = $payload;
    }

    public function scriptFailure(string $key, \Throwable $exception): void
    {
        $this->failures[$key] = $exception;
    }

    /**
     * Builds an ApiException with a 422-style envelope, mirroring the
     * error mapping of the real client.
     */
    public static function validationError(string $message = 'Validation failed.'): ApiException
    {
        $apiError = new ApiError('validation_error', 'invalid_payload', $message);

        return new ValidationException($message, 422, $apiError, []);
    }

    public static function transportError(string $message = 'Connection reset.'): TransportException
    {
        return new TransportException($message);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function mutate(string $method, string $key, ?array $payload, string $prefix): array
    {
        $failureKey = $method.':'.substr($key, strpos($key, ':') + 1);

        // Record the attempt before any scripted failure so tests can
        // assert exactly one mutation attempt happened.
        $this->calls[] = ['method' => $method, 'target' => $key, 'payload' => $payload];

        if (isset($this->failures[$failureKey])) {
            throw $this->failures[$failureKey];
        }

        if ($method === 'deleteTool' || $method === 'deleteAgent' || $method === 'deleteTeam') {
            unset($this->server[$key]);

            return [];
        }

        $existing = $this->server[$key] ?? null;
        $now = '2026-08-28T10:00:00Z';

        $remote = array_merge($payload ?? [], [
            'id' => $existing['id'] ?? $prefix.'_'.($payload['name'] ?? basename($key)),
            'created_at' => $existing['created_at'] ?? '2026-01-01T00:00:00Z',
            'updated_at' => $now,
        ]);

        $this->server[$key] = $remote;

        return $remote;
    }
}
