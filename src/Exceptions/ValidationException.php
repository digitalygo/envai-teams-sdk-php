<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown for client-side request errors: HTTP 422 validation_error and
 * invalid_request envelopes.
 */
final class ValidationException extends ApiException {}
