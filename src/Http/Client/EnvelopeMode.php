<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Client;

/**
 * How the response body of an apiRequest() should be decoded.
 *
 * Envelope unwraps the single-resource {data: {...}} wrapper, Collection
 * hydrates a paginated {data: [...]} payload into a ResourceCollection and
 * TopLevel decodes an unwrapped top-level object (capabilities, JWKS, model
 * catalog, chat execution acknowledgments).
 */
enum EnvelopeMode
{
    case Envelope;

    case Collection;

    case TopLevel;
}
