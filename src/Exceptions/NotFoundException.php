<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown when the requested resource does not exist (HTTP 404 or not_found
 * envelope).
 */
final class NotFoundException extends ApiException {}
