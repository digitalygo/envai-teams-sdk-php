<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Creates the sync state tables of the Envai Laravel integration
 *. Publish with:
 *
 *     php artisan vendor:publish --tag=envai-migrations
 *
 * The logical schema matches PdoSyncStateStore and
 * LaravelDatabaseSyncStateStore: the resources table keyed uniquely by
 * (connection, resource_type, local_key) and the manifests table keyed
 * by connection, holding the last manifest marked ready.
 *
 * The {prefix}locks table used for cross-process locking is NOT part of
 * the documented state schema: LaravelDatabaseSyncStateStore ensures it
 * lazily (idempotent) on the first lock acquisition, and
 * PdoSyncStateStore creates it through its own ensureSchema(). Consumers
 * who prefer the migration to own every table may add the locks table
 * themselves:
 *
 *     Schema::create('envai_sync_locks', function (Blueprint $table) {
 *         $table->string('connection', 191)->primary();
 *         $table->string('owner', 64);
 *         $table->string('acquired_at', 64);
 *     });
 *
 * The consumer decides the database connection the migration runs on
 * and the execution schedule; the provider never creates tables.
 */

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envai_sync_resources', function (Blueprint $table): void {
            $table->string('connection', 191);
            $table->string('resource_type', 64);
            $table->string('local_key', 191);
            $table->string('remote_id', 191)->nullable();
            $table->string('remote_name', 191)->nullable();
            $table->string('desired_hash', 191)->nullable();
            $table->text('last_applied_payload')->nullable();
            $table->text('remote_marker')->nullable();
            $table->text('state_metadata')->nullable();
            $table->string('status', 32);
            $table->string('synchronized_at', 64)->nullable();
            $table->string('created_at', 64);
            $table->string('updated_at', 64);

            $table->primary(['connection', 'resource_type', 'local_key']);
        });

        Schema::create('envai_sync_manifests', function (Blueprint $table): void {
            $table->string('connection', 191)->primary();
            $table->string('manifest_hash', 191);
            $table->string('synchronized_at', 64);
            $table->text('metadata');
        });
        // This explicit migration creates empty tables. Identity bytes must
        // remain distinct regardless of database collation or trailing spaces.
        $connection = Schema::getConnection();
        if ($connection->getDriverName() === 'mysql') {
            $connection->statement('ALTER TABLE '.$connection->getQueryGrammar()->wrapTable('envai_sync_resources').' MODIFY connection VARBINARY(764) NOT NULL, MODIFY resource_type VARBINARY(64) NOT NULL, MODIFY local_key VARBINARY(764) NOT NULL');
            $connection->statement('ALTER TABLE '.$connection->getQueryGrammar()->wrapTable('envai_sync_manifests').' MODIFY connection VARBINARY(764) NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('envai_sync_manifests');
        Schema::dropIfExists('envai_sync_resources');
    }
};
