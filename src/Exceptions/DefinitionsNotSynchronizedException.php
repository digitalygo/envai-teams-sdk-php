<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown by high-level helpers that use local definition keys while the local
 * manifest is not the last manifest marked ready on the connection.
 */
final class DefinitionsNotSynchronizedException extends EnvaiException {}
