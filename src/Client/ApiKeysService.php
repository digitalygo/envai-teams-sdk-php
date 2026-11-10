<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Models\ApiKey\ApiKey;
use Digitalygo\Envai\Models\ApiKey\ApiKeyCreated;
use Digitalygo\Envai\Models\ApiKey\ApiKeyCreateRequest;
use Digitalygo\Envai\Models\Common\ResourceCollection;

/**
 * API key management requires a human browser session and CSRF protection.
 * This bearer/project-key client does not implement that authentication flow.
 * Methods remain discoverable but fail locally without sending credentials.
 */
final readonly class ApiKeysService
{
    private const UNSUPPORTED = 'API key management requires a human session and CSRF protection; bearer/project-key authentication is not supported. Manage keys in the Envai web application.';

    public function create(ApiKeyCreateRequest $request): ApiKeyCreated
    {
        throw new \LogicException(self::UNSUPPORTED);
    }

    /**
     * @return ResourceCollection<ApiKey>
     */
    public function list(): ResourceCollection
    {
        throw new \LogicException(self::UNSUPPORTED);
    }

    public function delete(string $keyId): void
    {
        throw new \LogicException(self::UNSUPPORTED);
    }
}
