<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\System;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Body of the unauthenticated GET /up health check.
 *
 * Timestamps stay ISO 8601 strings (wire-faithful); the SDK never parses
 * them into DateTimeImmutable objects. Only `status` is semantically
 * required by this DTO: the schema marks every field optional, but a health
 * payload without a status is malformed, so hydration fails loudly instead
 * of inventing one.
 */
final readonly class HealthStatus implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $status,
        public ?string $timestamp = null,
        public ?string $app = null,
        public ?string $env = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'HealthStatus';

        return new self(
            status: Wire::string($data, 'status', $context),
            timestamp: Wire::nullableString($data, 'timestamp', $context),
            app: Wire::nullableString($data, 'app', $context),
            env: Wire::nullableString($data, 'env', $context),
            extra: Wire::extra($data, ['status', 'timestamp', 'app', 'env']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'status' => $this->status,
        ];

        if ($this->timestamp !== null) {
            $payload['timestamp'] = $this->timestamp;
        }

        if ($this->app !== null) {
            $payload['app'] = $this->app;
        }

        if ($this->env !== null) {
            $payload['env'] = $this->env;
        }

        return array_merge($payload, $this->extra);
    }
}
