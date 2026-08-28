<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown when the request conflicts with the current server state (HTTP 409
 * or conflict envelope).
 */
final class ConflictException extends ApiException {}
