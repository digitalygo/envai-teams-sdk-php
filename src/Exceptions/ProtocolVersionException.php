<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Thrown when the server speaks an ATP protocol version the SDK does not
 * support.
 */
final class ProtocolVersionException extends ProtocolViolationException {}
