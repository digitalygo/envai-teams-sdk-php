<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown when the reconciler detects a conflict between desired state, last
 * applied state and the remote state that no explicit override authorizes.
 */
final class DefinitionConflictException extends EnvaiException {}
