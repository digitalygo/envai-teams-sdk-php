<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

/**
 * Network level failures: connection refused, DNS errors, TLS errors, timeouts
 * and write failures. Messages must never embed credentials.
 */
final class TransportException extends EnvaiException {}
