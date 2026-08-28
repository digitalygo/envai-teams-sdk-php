<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Compiler;

use Digitalygo\Envai\Definitions\Manifest\DefinitionManifest;
use Digitalygo\Envai\Definitions\Validation\DefinitionViolation;

/**
 * Result of compiling the local `envai/` definitions directory. The
 * manifest is always built, even when violations are present; the valid
 * flag is true only when there are no violations.
 */
final readonly class DefinitionCompilationResult
{
    /**
     * @param  list<DefinitionViolation>  $violations
     */
    public function __construct(
        public DefinitionManifest $manifest,
        public array $violations,
        public bool $valid,
    ) {}
}
