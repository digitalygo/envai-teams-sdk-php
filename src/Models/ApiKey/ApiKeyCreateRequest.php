<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ApiKey;

/**
 * Payload to create an api key. `user_id` is prohibited: the owner is the
 * authenticated user. `scopes` defaults to `["completions:write"]` and
 * `expires_at` defaults to `30d`.
 */
final readonly class ApiKeyCreateRequest
{
    /**
     * @param  list<ApiKeyScope>  $scopes
     */
    public function __construct(
        public string $name,
        public array $scopes = [ApiKeyScope::CompletionsWrite],
        public ApiKeyExpiry $expiresAt = ApiKeyExpiry::D30D,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'scopes' => array_map(static fn (ApiKeyScope $scope): string => $scope->value, $this->scopes),
            'expires_at' => $this->expiresAt->value,
        ];
    }
}
