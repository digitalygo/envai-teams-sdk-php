<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Signals a gap in the ATP replay window that cannot be recovered: the SDK
 * observed events beyond missingFrom but never received missingTo.
 */
final class ReplayGapException extends EnvaiException
{
    public function __construct(
        string $message,
        public readonly int $missingFrom,
        public readonly int $missingTo,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $context, $previous);
    }
}
