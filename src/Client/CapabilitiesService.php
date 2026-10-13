<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Exceptions\ContractCompatibilityException;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Models\System\Capabilities;
use Digitalygo\Envai\Models\System\HealthStatus;
use Digitalygo\Envai\Models\System\JwksDocument;

/**
 * System and discovery operations: advertised capabilities, the
 * unauthenticated health check and the public JWKS endpoint.
 *
 * healthCheck() and jwks() are public and carry no Authorization header; the
 * JWKS well-known endpoint lives at the base URL origin, never under the
 * /api/v1 prefix.
 */
final readonly class CapabilitiesService
{
    private TypedRequester $requests;

    public function __construct(
        RequestExecutor $executor,
        UriResolver $uris,
        int $connectTimeoutSeconds = 10,
        int $requestTimeoutSeconds = 60,
        private int $contractVersion = 3,
    ) {
        $this->requests = new TypedRequester($executor, $uris, $connectTimeoutSeconds, $requestTimeoutSeconds);
    }

    public function wikiWebhookJwks(): JwksDocument
    {
        $result = $this->requests->absolute('GET', $this->requests->urlForWikiWebhookJwks(), [], null, JwksDocument::class, EnvelopeMode::TopLevel);

        /** @var JwksDocument $result */
        return $result;
    }

    /**
     * Returns the protocol metadata and positively advertised names
     * (GET /capabilities).
     */
    public function getCapabilitiesLegacy(): Capabilities
    {
        if ($this->contractVersion !== 2) {
            throw new ContractCompatibilityException('Legacy capabilities require explicit contract 2 read-only mode.');
        }

        return $this->getCapabilities();
    }

    public function getCapabilities(): Capabilities
    {
        $result = $this->requests->request(
            'GET',
            '/atp/v3/capabilities',
            [],
            null,
            Capabilities::class,
            EnvelopeMode::TopLevel,
        );

        /** @var Capabilities $result */
        return $result;
    }

    /**
     * Returns the health status of the API (GET /up, public).
     */
    public function healthCheck(): HealthStatus
    {
        $result = $this->requests->request(
            'GET',
            '/up',
            [],
            null,
            HealthStatus::class,
            EnvelopeMode::Envelope,
            AuthenticationMode::None,
        );

        /** @var HealthStatus $result */
        return $result;
    }

    /**
     * Publishes the JSON Web Key Set of the control plane signing keys
     * (GET /.well-known/jwks.json, public, resolved from the base URL
     * origin).
     */
    public function jwks(): JwksDocument
    {
        $result = $this->requests->absolute(
            'GET',
            $this->requests->urlForJwks(),
            [],
            null,
            JwksDocument::class,
            EnvelopeMode::TopLevel,
        );

        /** @var JwksDocument $result */
        return $result;
    }
}
