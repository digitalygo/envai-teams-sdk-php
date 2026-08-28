<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown when the wire protocol is violated: malformed SSE frames, invalid
 * envelopes, malformed JSON where a typed event was expected.
 */
class ProtocolViolationException extends EnvaiException {}
