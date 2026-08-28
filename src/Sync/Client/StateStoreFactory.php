<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Client;

use Digitalygo\Envai\Sync\State\File\FileSyncStateStore;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Sync\State\Pdo\PdoSyncStateStore;
use Digitalygo\Envai\Sync\State\SyncStateStore;

/**
 * Creates a SyncStateStore from a driver name and its options.
 *
 * Supported drivers:
 *
 * - `inmemory`: a single-process store that never persists;
 * - `file`: the JSON file store, with the state file path in
 *   `options['path']` (default `.envai`);
 * - `pdo`: the database store, with a PDO instance in `options['pdo']`
 *   (mandatory) and the table prefix in `options['table_prefix']`
 *   (default `envai_sync_`).
 *
 * An unknown driver throws an InvalidArgumentException listing the
 * supported drivers, so configuration mistakes surface before any store
 * is used.
 */
final class StateStoreFactory
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function create(string $driver, array $options = []): SyncStateStore
    {
        return match (strtolower($driver)) {
            'inmemory' => new InMemorySyncStateStore,
            'file' => new FileSyncStateStore(path: self::stringOption($options, 'path', '.envai')),
            'pdo' => $this->pdoStore($options),
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown sync state driver "%s"; supported drivers: inmemory, file, pdo.',
                $driver,
            )),
        };
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function pdoStore(array $options): PdoSyncStateStore
    {
        $pdo = $options['pdo'] ?? null;

        if (! $pdo instanceof \PDO) {
            throw new \InvalidArgumentException(
                'The "pdo" state driver requires a PDO instance in the "pdo" option.',
            );
        }

        return new PdoSyncStateStore(
            $pdo,
            self::stringOption($options, 'table_prefix', 'envai_sync_'),
        );
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private static function stringOption(array $options, string $key, string $default): string
    {
        $value = $options[$key] ?? $default;

        if (! is_string($value)) {
            throw new \InvalidArgumentException(sprintf(
                'The state driver option "%s" must be a string.',
                $key,
            ));
        }

        return $value;
    }
}
