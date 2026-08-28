<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown when the caller is authenticated but not authorized (HTTP 403 or
 * access_denied envelope).
 */
final class AccessDeniedException extends ApiException {}
