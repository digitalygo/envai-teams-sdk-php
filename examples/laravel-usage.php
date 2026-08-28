<?php

declare(strict_types=1);

/*
 * Illustrative example: Laravel integration usage.
 *
 * This file is not executed as a script; it shows the registration
 * steps and the container-driven usage inside a Laravel application.
 */

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Models\Workspace\WorkspaceCreateRequest;
use Digitalygo\Envai\Sync\DefinitionsService;

/*
 * 1. Register the provider, for example in bootstrap/providers.php:
 *
 *     Digitalygo\Envai\Integration\Laravel\EnvaiServiceProvider::class,
 *
 * 2. Publish the configuration and the migration:
 *
 *     php artisan vendor:publish --tag=envai-config
 *     php artisan vendor:publish --tag=envai-migrations
 *     php artisan migrate
 *
 *    Then set ENVAI_BASE_URL and ENVAI_API_KEY in your .env, and
 *    optionally ENVAI_STATE_DRIVER=laravel with
 *    ENVAI_STATE_DB_CONNECTION=<your connection> to use the database
 *    state store on a connection you choose.
 *
 * 3. Resolve the client and the definitions facade from the container.
 *    All bindings are lazy: nothing touches the network or the database
 *    until a service is resolved, and the provider never synchronizes
 *    at boot.
 */

/** @var EnvaiClient $envai */
$envai = app('envai'); // alias of envai.client (default connection)

/** @var DefinitionsService $definitions */
$definitions = app(DefinitionsService::class); // alias of envai.definitions

/*
 * 4. High-level chat creation is gated on definitions readiness: the
 *    local manifest must match the last manifest marked ready on the
 *    connection, otherwise DefinitionsNotSynchronizedException is thrown.
 */
$definitions->requireSynchronized();

$workspace = $envai->workspaces()->create(
    new WorkspaceCreateRequest(name: 'Forecasting'),
);

$chat = $envai->workspace($workspace->id)
    ->chats()
    ->create(team: $definitions->team('forecasting'));

/*
 * 5. Deploy flow through the Artisan aliases (sync_mode deploy, the
 *    default, never gates automatically):
 *
 *     php artisan envai:validate
 *     php artisan envai:plan --fail-on-drift
 *     php artisan envai:sync
 *
 *    Or run the sync manually from code:
 *
 *     $report = $definitions->apply();
 *
 * 6. Optional: build the database state store directly on any
 *    Illuminate connection (the `laravel` driver does this for you):
 *
 *     $store = new \Digitalygo\Envai\Integration\Laravel\LaravelDatabaseSyncStateStore(
 *         DB::connection('envai'),
 *         'envai_sync_',
 *     );
 *
 *    The same logical schema is implemented by PdoSyncStateStore for a
 *    consumer-supplied PDO connection:
 *
 *     $pdo = DB::connection('envai')->getPdo();
 *     $store = new PdoSyncStateStore($pdo, autoMigrate: true);
 *     $store->ensureSchema();
 */
