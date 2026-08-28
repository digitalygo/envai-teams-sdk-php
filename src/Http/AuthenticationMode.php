<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

/**
 * How the request should be authenticated.
 */
enum AuthenticationMode
{
    case Bearer;

    case None;
}
