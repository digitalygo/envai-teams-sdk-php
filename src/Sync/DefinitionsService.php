<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync;

use Digitalygo\Envai\Definitions\Compiler\DefinitionCompilationResult;
use Digitalygo\Envai\Definitions\Compiler\DefinitionCompiler;
use Digitalygo\Envai\Definitions\Manifest\DefinitionManifest;
use Digitalygo\Envai\Exceptions\DefinitionsNotSynchronizedException;
use Digitalygo\Envai\Sync\Planner\RemoteReader;
use Digitalygo\Envai\Sync\Planner\SyncOptions;
use Digitalygo\Envai\Sync\Planner\SyncPlan;
use Digitalygo\Envai\Sync\Planner\SyncPlanner;
use Digitalygo\Envai\Sync\Reconciler\RemoteWriter;
use Digitalygo\Envai\Sync\Reconciler\SyncReconciler;
use Digitalygo\Envai\Sync\Reconciler\SyncReport;
use Digitalygo\Envai\Sync\State\ReadyManifest;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Sync\State\SyncLock;
use Digitalygo\Envai\Sync\State\SyncStateStore;
use Digitalygo\Envai\Tools\Contracts\ClientToolFactory;
use Psr\Log\LoggerInterface;

/**
 * High-level definitions facade (project document sections 22.1 and
 * 23.3): compile, plan, apply and gate on the readiness of one
 * connection/account + manifest hash.
 *
 * Lock behavior is binding:
 *
 * - validate() is a pure local phase (no lock, no remote read);
 * - plan() acquires the lock, compiles, reads the remote resources,
 *   builds the plan and releases the lock before returning, so a plan
 *   is never handed out while the connection is locked;
 * - apply() holds one lock acquisition across plan and reconcile,
 *   including the manifest readiness marking, and releases it in a
 *   finally block;
 * - requireSynchronized(), team(), agent(), tool(), manifest() and
 *   readyManifest() read state and never acquire the lock.
 *
 * The compiled result is produced without the model catalog (model
 * validation is skipped); the CLI wiring may pass the catalog through
 * the compiler separately.
 */
final class DefinitionsService
{
    private SyncPlanner $planner;

    private SyncReconciler $reconciler;

    public function __construct(
        private readonly DefinitionCompiler $compiler,
        private readonly SyncStateStore $store,
        private readonly RemoteReader $remoteReader,
        private readonly RemoteWriter $remoteWriter,
        private readonly string $connection = 'default',
        private readonly ?string $definitionsPath = null,
        private readonly ?ClientToolFactory $factory = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->planner = new SyncPlanner($remoteReader, $store);
        $this->reconciler = new SyncReconciler($remoteWriter, $store);
    }

    /**
     * Compiles and validates the local definitions. Local phase only:
     * no lock, no remote reads, no writes.
     */
    public function validate(): DefinitionCompilationResult
    {
        $compiled = $this->compile();

        $this->logger?->info(sprintf(
            'Validated definitions at "%s" for connection "%s": %d tool(s), %d agent(s), %d team(s), %d violation(s).',
            $this->definitionsPath ?? 'envai',
            $this->connection,
            count($compiled->manifest->tools),
            count($compiled->manifest->agents),
            count($compiled->manifest->teams),
            count($compiled->violations),
        ));

        return $compiled;
    }

    /**
     * Builds a plan for the current definitions against the remote
     * resources. The lock is held for the whole local + remote phase but
     * released before the plan is returned.
     */
    public function plan(?SyncOptions $options = null): SyncPlan
    {
        $lock = $this->store->acquireLock($this->connection);

        try {
            return $this->planLocked($options ?? new SyncOptions);
        } finally {
            $lock->release();
        }
    }

    /**
     * Plans and reconciles under a single lock acquisition, marking the
     * manifest ready when the report succeeds with a complete scope.
     */
    public function apply(?SyncOptions $options = null): SyncReport
    {
        $lock = $this->store->acquireLock($this->connection);

        try {
            return $this->applyLocked($options ?? new SyncOptions, $lock);
        } finally {
            $lock->release();
        }
    }

    /**
     * Fails when the current local manifest is not the last manifest
     * marked ready on the connection: either no manifest was ever marked
     * ready or its hash differs from the freshly compiled one.
     *
     * @throws DefinitionsNotSynchronizedException
     */
    public function requireSynchronized(): void
    {
        $compiled = $this->compile();
        $ready = $this->store->readyManifest($this->connection);

        if ($ready === null) {
            throw new DefinitionsNotSynchronizedException(sprintf(
                'Definitions for connection "%s" were never synchronized; run envai definitions:sync first.',
                $this->connection,
            ));
        }

        if ($ready->manifestHash !== $compiled->manifest->hash) {
            throw new DefinitionsNotSynchronizedException(sprintf(
                'Definitions for connection "%s" are out of date: the last ready manifest is %s but the local definitions compile to %s. Run envai definitions:sync first.',
                $this->connection,
                $ready->manifestHash,
                $compiled->manifest->hash,
            ));
        }
    }

    /**
     * Remote team id for a local team key, gated on synchronization.
     *
     * @throws DefinitionsNotSynchronizedException
     */
    public function team(string $localKey): string
    {
        return $this->remoteIdentity(ResourceType::Team, $localKey);
    }

    /**
     * Remote agent id for a local agent key, gated on synchronization.
     *
     * @throws DefinitionsNotSynchronizedException
     */
    public function agent(string $localKey): string
    {
        return $this->remoteIdentity(ResourceType::Agent, $localKey);
    }

    /**
     * Remote identity of a local tool key, gated on synchronization.
     * Tools are addressed by name, so the remote name is returned when
     * available, falling back to the remote id.
     *
     * @throws DefinitionsNotSynchronizedException
     */
    public function tool(string $localKey): string
    {
        return $this->remoteIdentity(ResourceType::Tool, $localKey);
    }

    /**
     * The compiled manifest for the current local definitions. Not gated
     * on synchronization.
     */
    public function manifest(): DefinitionManifest
    {
        return $this->compile()->manifest;
    }

    /**
     * The last manifest marked ready on the connection, or null when the
     * connection was never synchronized.
     */
    public function readyManifest(): ?ReadyManifest
    {
        return $this->store->readyManifest($this->connection);
    }

    private function planLocked(SyncOptions $options): SyncPlan
    {
        $compiled = $this->compile();

        $this->logger?->info(sprintf(
            'Planning definitions for connection "%s".',
            $this->connection,
        ));

        return $this->planner->plan($compiled, $options, $this->connection);
    }

    private function applyLocked(SyncOptions $options, SyncLock $lock): SyncReport
    {
        $compiled = $this->compile();

        $this->logger?->info(sprintf(
            'Applying definitions for connection "%s".',
            $this->connection,
        ));

        $plan = $this->planner->plan($compiled, $options, $this->connection);

        return $this->reconciler->apply($plan, $compiled->manifest, $lock);
    }

    private function compile(): DefinitionCompilationResult
    {
        return $this->compiler->compile(
            $this->definitionsPath ?? 'envai',
            $this->factory,
            null,
        );
    }

    /**
     * @throws DefinitionsNotSynchronizedException
     */
    private function remoteIdentity(ResourceType $type, string $localKey): string
    {
        $this->requireSynchronized();

        $state = $this->store->getResource($this->connection, $type, $localKey);

        if ($state === null) {
            throw new DefinitionsNotSynchronizedException(sprintf(
                'No remote %s "%s" is registered for connection "%s"; synchronize the definitions first.',
                $type->value,
                $localKey,
                $this->connection,
            ));
        }

        $identity = match ($type) {
            ResourceType::Tool => $state->remoteName ?? $state->remoteId,
            ResourceType::Agent, ResourceType::Team => $state->remoteId,
        };

        if ($identity === null) {
            throw new DefinitionsNotSynchronizedException(sprintf(
                'The remote %s "%s" is registered but has no remote identity for connection "%s"; synchronize the definitions first.',
                $type->value,
                $localKey,
                $this->connection,
            ));
        }

        return $identity;
    }
}
