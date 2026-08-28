<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ApiKey;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Api key metadata. The plaintext token is never included: it appears only
 * in the 201 creation response (ApiKeyCreated). `expires_at` is null when
 * the key never expires and `revoked_at` is null while the key is active.
 *
 * Timestamps stay ISO 8601 strings (wire-faithful), never parsed into
 * DateTimeImmutable.
 */
final readonly class ApiKey implements ArrayHydratable
{
    /**
     * @param  list<ApiKeyScope>  $scopes
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $scopes,
        public ?string $expiresAt,
        public ?string $revokedAt,
        public string $createdAt,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'ApiKey';

        return new self(
            id: Wire::string($data, 'id', $context),
            name: Wire::string($data, 'name', $context),
            scopes: Wire::enumList($data, 'scopes', ApiKeyScope::class, $context),
            expiresAt: Wire::nullableString($data, 'expires_at', $context),
            revokedAt: Wire::nullableString($data, 'revoked_at', $context),
            createdAt: Wire::string($data, 'created_at', $context),
            extra: Wire::extra($data, ['id', 'name', 'scopes', 'expires_at', 'revoked_at', 'created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'name' => $this->name,
            'scopes' => array_map(static fn (ApiKeyScope $scope): string => $scope->value, $this->scopes),
            'expires_at' => $this->expiresAt,
            'revoked_at' => $this->revokedAt,
            'created_at' => $this->createdAt,
        ], $this->extra);
    }
}
