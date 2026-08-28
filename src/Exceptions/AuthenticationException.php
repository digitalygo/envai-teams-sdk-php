<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown for unauthenticated responses (HTTP 401 or unauthenticated envelope).
 */
final class AuthenticationException extends ApiException {}
