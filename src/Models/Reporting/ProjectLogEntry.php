<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Json\JsonObject;
use InvalidArgumentException;
use JsonSerializable;

final readonly class ProjectLogEntry implements ArrayHydratable, JsonSerializable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $id,
        public string $projectId,
        public ?string $apiKeyId,
        public ?string $actorApiKeyId,
        public string $chatId,
        public string $workspaceId,
        public int $seq,
        public ?string $turnId,
        public string $type,
        public string $ts,
        public JsonObject $agent,
        public JsonObject $data,
        public array $extra = [],
    ) {
        if ($seq < 0) {
            throw new InvalidArgumentException('Project log sequence must not be negative.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            id: Wire::string($data, 'id', 'ProjectLogEntry'),
            projectId: Wire::string($data, 'project_id', 'ProjectLogEntry'),
            apiKeyId: ReportingWire::requiredNullableString($data, 'api_key_id', 'ProjectLogEntry'),
            actorApiKeyId: ReportingWire::requiredNullableString($data, 'actor_api_key_id', 'ProjectLogEntry'),
            chatId: Wire::string($data, 'chat_id', 'ProjectLogEntry'),
            workspaceId: Wire::string($data, 'workspace_id', 'ProjectLogEntry'),
            seq: Wire::int($data, 'seq', 'ProjectLogEntry'),
            turnId: ReportingWire::requiredNullableString($data, 'turn_id', 'ProjectLogEntry'),
            type: Wire::string($data, 'type', 'ProjectLogEntry'),
            ts: Wire::string($data, 'ts', 'ProjectLogEntry'),
            agent: ReportingWire::jsonObject($data, 'agent', 'ProjectLogEntry'),
            data: ReportingWire::jsonObject($data, 'data', 'ProjectLogEntry'),
            extra: Wire::extra($data, ['id', 'project_id', 'api_key_id', 'actor_api_key_id', 'chat_id', 'workspace_id', 'seq', 'turn_id', 'type', 'ts', 'agent', 'data']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'project_id' => $this->projectId,
            'api_key_id' => $this->apiKeyId,
            'actor_api_key_id' => $this->actorApiKeyId,
            'chat_id' => $this->chatId,
            'workspace_id' => $this->workspaceId,
            'seq' => $this->seq,
            'turn_id' => $this->turnId,
            'type' => $this->type,
            'ts' => $this->ts,
            'agent' => $this->agent->toArray(),
            'data' => $this->data->toArray(),
        ], $this->extra);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = $this->toArray();
        $data['agent'] = $this->agent->jsonSerialize();
        $data['data'] = $this->data->jsonSerialize();

        return $data;
    }
}
