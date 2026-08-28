<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown when the server failed to fulfill the request (HTTP 5xx, server_error
 * or internal_error envelope).
 */
final class ServerException extends ApiException {}
