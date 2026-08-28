<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown by cooperative cancellation: CancellationToken::throwIfCancelled()
 * raises this once cancellation has been requested.
 */
final class OperationCancelledException extends EnvaiException {}
