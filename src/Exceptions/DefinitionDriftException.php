<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown when a managed remote resource changed after the last applied state
 * and the policy fails closed instead of overwriting.
 */
final class DefinitionDriftException extends EnvaiException {}
