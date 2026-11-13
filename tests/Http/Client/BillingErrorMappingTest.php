<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

use Digitalygo\Envai\Configuration\RetryPolicy;
use Digitalygo\Envai\Exceptions\ApiException;
use Digitalygo\Envai\Exceptions\PaymentRequiredException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Http\Client\ErrorMapper;
use Digitalygo\Envai\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

final class BillingErrorMappingTest extends TestCase
{
    public function test_both_mapping_paths_preserve_billing_errors(): void
    {
        $body = json_encode(['error' => ['type' => 'billing_error', 'code' => 'credit_exhausted', 'message' => 'No credit', 'details' => ['scope' => 'company']]], JSON_THROW_ON_ERROR);

        foreach ([
            ApiException::fromResponse(new HttpResponse(402, [], $body)),
            ErrorMapper::fromStatusAndBody(402, $body, []),
            ApiException::fromResponse(new HttpResponse(402)),
            ErrorMapper::fromStatusAndBody(402, null, []),
        ] as $error) {
            self::assertInstanceOf(PaymentRequiredException::class, $error);
            self::assertInstanceOf(ApiException::class, $error);
            self::assertSame(402, $error->httpStatus);
        }

        foreach ([
            ApiException::fromResponse(new HttpResponse(402, [], $body)),
            ErrorMapper::fromStatusAndBody(402, $body, []),
        ] as $error) {
            self::assertSame('billing_error', $error->apiError?->type);
            self::assertSame('credit_exhausted', $error->apiError?->code);
            self::assertSame(['scope' => 'company'], $error->apiError?->details);
            self::assertSame('No credit', $error->getMessage());
        }
    }

    public function test_default_retry_policy_excludes_payment_required(): void
    {
        $policy = new RetryPolicy;

        self::assertFalse($policy->isRetryableStatus(402));
        self::assertTrue($policy->isRetryableStatus(503));
    }

    public function test_provider_configuration_stays_catchable_as_server_exception(): void
    {
        $body = '{"error":{"type":"provider_configuration_error","code":"provider_configuration_error","message":"Unavailable"}}';

        self::assertTrue((new \ReflectionClass(ServerException::class))->isFinal());

        foreach ([
            ApiException::fromResponse(new HttpResponse(503, [], $body)),
            ErrorMapper::fromStatusAndBody(503, $body, []),
        ] as $error) {
            self::assertInstanceOf(ServerException::class, $error);
            self::assertInstanceOf(ApiException::class, $error);
            self::assertSame(503, $error->httpStatus);
            self::assertSame('provider_configuration_error', $error->apiError?->type);
            self::assertSame('provider_configuration_error', $error->apiError?->code);
            self::assertSame('Unavailable', $error->getMessage());
        }
    }
}
