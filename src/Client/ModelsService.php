<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\TypedRequester;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Models\Model\ModelList;

/**
 * Control plane operations over the LLM model catalog.
 *
 * The catalog mixes LLM entries and team alias entries and answers a
 * top-level `{object, data, defaults}` object without a data wrapper.
 */
final readonly class ModelsService
{
    private TypedRequester $requests;

    public function __construct(
        RequestExecutor $executor,
        UriResolver $uris,
        int $connectTimeoutSeconds = 10,
        int $requestTimeoutSeconds = 60,
    ) {
        $this->requests = new TypedRequester($executor, $uris, $connectTimeoutSeconds, $requestTimeoutSeconds);
    }

    /**
     * Lists the LLM model catalog (GET /models).
     */
    public function listModels(): ModelList
    {
        $result = $this->requests->request(
            'GET',
            '/models',
            [],
            null,
            ModelList::class,
            EnvelopeMode::TopLevel,
        );

        /** @var ModelList $result */
        return $result;
    }
}
