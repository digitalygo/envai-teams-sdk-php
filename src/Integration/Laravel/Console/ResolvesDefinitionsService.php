<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\Console;

use Digitalygo\Envai\Sync\DefinitionsService;
use Illuminate\Console\Command;

/**
 * Shared wiring of the Artisan alias commands: the DefinitionsService
 * and the connection name come from the constructor when injected
 * (tests, advanced usage) and otherwise from the container-bound
 * configuration (config/envai.php).
 */
trait ResolvesDefinitionsService
{
    protected function resolveDefinitions(): DefinitionsService
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        if (! $this instanceof Command || $this->laravel === null) {
            throw new \LogicException('No DefinitionsService available: inject one or run the command through the container.');
        }

        return $this->laravel->make(DefinitionsService::class);
    }

    /**
     * The connection the container-bound definitions service operates
     * on: config('envai.default') when available, `production`
     * otherwise.
     */
    protected function resolveConnection(): string
    {
        if (is_string($this->connection) && $this->connection !== '') {
            return $this->connection;
        }

        if (! $this instanceof Command || $this->laravel === null) {
            return 'production';
        }

        try {
            $config = $this->laravel->make('config');
        } catch (\Throwable) {
            return 'production';
        }

        $name = is_object($config) && method_exists($config, 'get') ? $config->get('envai.default') : null;

        return is_string($name) && $name !== '' ? $name : 'production';
    }
}
