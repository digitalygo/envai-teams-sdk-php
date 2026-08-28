<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ApiKey;

/**
 * Expiration window accepted by the api key creation payload. `never`
 * creates a key without expiry that stays valid until revocation.
 */
enum ApiKeyExpiry: string
{
    case D30D = '30d';
    case D90D = '90d';
    case Y1 = '1y';
    case Never = 'never';
}
