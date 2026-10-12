<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Image generation record returned at 202. `provider` is the provider
 * selected by the router, `routed` tells whether the request was routed to a
 * provider different from the initial one and `status_url` is the URL to
 * poll the generation status. Timestamps stay ISO 8601 strings
 * (wire-faithful).
 */
final readonly class ImageGenerationAcceptedRecord implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public ImageGenerationLifecycleStatus $status,
        public string $provider,
        public string $initialProvider,
        public bool $routed,
        public string $model,
        public string $webhookUrl,
        public string $statusUrl,
        public string $createdAt,
        public array $extra = [],
        public ?string $expiresAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'ImageGenerationAcceptedRecord';

        return new self(
            id: Wire::string($data, 'id', $context),
            status: Wire::enum($data, 'status', ImageGenerationLifecycleStatus::class, $context),
            provider: Wire::string($data, 'provider', $context),
            initialProvider: Wire::string($data, 'initial_provider', $context),
            routed: Wire::bool($data, 'routed', $context),
            model: Wire::string($data, 'model', $context),
            webhookUrl: Wire::string($data, 'webhook_url', $context),
            statusUrl: Wire::string($data, 'status_url', $context),
            createdAt: Wire::string($data, 'created_at', $context),
            extra: Wire::extra($data, ['id', 'status', 'provider', 'initial_provider', 'routed', 'model', 'webhook_url', 'status_url', 'created_at', 'expires_at']),
            expiresAt: Wire::nullableString($data, 'expires_at', $context),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'status' => $this->status->value,
            'provider' => $this->provider,
            'initial_provider' => $this->initialProvider,
            'routed' => $this->routed,
            'model' => $this->model,
            'webhook_url' => $this->webhookUrl,
            'status_url' => $this->statusUrl,
            'created_at' => $this->createdAt,
        ], $this->expiresAt === null ? [] : ['expires_at' => $this->expiresAt], $this->extra);
    }
}
