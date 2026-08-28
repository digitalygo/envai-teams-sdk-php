<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown when the provider behind an operation fails (provider_error
 * envelope).
 */
final class ProviderException extends ApiException {}
