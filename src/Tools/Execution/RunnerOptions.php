<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Execution;

use Digitalygo\Envai\Contracts\CancellationToken;

/**
 * Tunables of the ClientToolRunner.
 *
 * `maxConcurrent` caps the number of handlers executing simultaneously
 * inside one runner instance; requests beyond the cap are refused with a
 * controlled `tool_busy` error instead of queueing. `submissionMarginSeconds`
 * is the conservative margin subtracted from the tool timeout before
 * deciding to submit: the server timer already started before the request
 * was signed and emitted, so the runner stops itself earlier to avoid racing
 * the server deadline. `allowSubmissionPastDeadline` disables that check for
 * callers that prefer to submit anyway and accept the resulting 409.
 */
final readonly class RunnerOptions
{
    public function __construct(
        public int $maxConcurrent = 4,
        public float $submissionMarginSeconds = 5.0,
        public bool $allowSubmissionPastDeadline = false,
        public ?CancellationToken $cancellationToken = null,
    ) {
        if ($maxConcurrent < 1) {
            throw new \InvalidArgumentException('maxConcurrent must be at least 1.');
        }

        if (! is_finite($submissionMarginSeconds) || $submissionMarginSeconds < 0.0) {
            throw new \InvalidArgumentException('submissionMarginSeconds must be finite and not negative.');
        }
    }
}
