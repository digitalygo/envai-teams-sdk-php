<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Validation;

/**
 * A single validation problem found while compiling local definitions.
 * The source key identifies the offending definition (the local key, or
 * an empty string for manifest-level problems), the field path locates
 * the field inside the definition and the code is a stable machine
 * readable identifier.
 */
final readonly class DefinitionViolation
{
    public function __construct(
        public string $sourceKey,
        public string $fieldPath,
        public string $code,
        public string $message,
    ) {}
}
