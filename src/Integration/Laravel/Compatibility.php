<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\ServiceProvider;

/**
 * Runtime capability guard of the optional Laravel integration.
 *
 * The core package never requires Illuminate: every class under
 * src/Integration/Laravel/ references Illuminate types and must only be
 * loaded inside a Laravel application (or an environment with the
 * Illuminate packages installed). illuminateAvailable() is the single
 * check the provider and the integration tests use to bail out cleanly
 * when the framework is absent.
 */
final class Compatibility
{
    private function __construct() {}

    /**
     * Whether the Illuminate components the integration needs are
     * available. The check triggers autoload of the two marker types;
     * either one present means the consumer has the Illuminate packages.
     */
    public static function illuminateAvailable(): bool
    {
        return class_exists(ServiceProvider::class)
            || interface_exists(Container::class);
    }

    /**
     * Whether the Illuminate database component (needed by the database
     * state store) is available.
     */
    public static function illuminateDatabaseAvailable(): bool
    {
        return class_exists(Connection::class)
            || interface_exists(ConnectionInterface::class);
    }
}
