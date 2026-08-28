<?php

declare(strict_types=1);

/*
 * Illustrative example: plan and apply the definitions sync
 * programmatically, then use the readiness gate.
 *
 * Run with:
 *
 *     ENVAI_API_KEY=... php examples/sync-workflow.php
 *
 * The definitions directory mirrors the envai/ layout (agents/*.md,
 * teams/*.php, tools.php).
 */

use Digitalygo\Envai\Exceptions\DefinitionsNotSynchronizedException;
use Digitalygo\Envai\Sync\Planner\SyncOptions;

$envai = require __DIR__.'/config.php';
$definitions = $envai->definitions(__DIR__.'/definitions');

// 1. Validate the local definitions. Pure local phase: no lock, no
//    remote reads, no writes.
$result = $definitions->validate();

printf(
    "Validated: %d tool(s), %d agent(s), %d team(s), %d violation(s)\n",
    count($result->manifest->tools),
    count($result->manifest->agents),
    count($result->manifest->teams),
    count($result->violations),
);

if (! $result->valid) {
    fwrite(STDERR, "Definitions are invalid; fix the violations first.\n");
    exit(1);
}

// 2. Build the three-way plan (desired vs last applied vs remote).
$plan = $definitions->plan();

foreach ($plan->blocking() as $operation) {
    printf("Blocked: %s (%s)\n", $operation->localKey, $operation->reasonCode ?? 'blocked');
}

// 3. Apply under a single lock acquisition. Every destructive override
//    must be explicit; none is activated implicitly.
$options = new SyncOptions(adopt: true, failOnDrift: true);
$report = $definitions->apply($options);

printf(
    "Apply status: %s | manifest ready: %s | summary: %s\n",
    $report->status->value,
    $report->manifestReady ? 'yes' : 'no',
    json_encode($report->summary),
);

// 4. The readiness gate. High-level helpers refuse to resolve local
//    keys while the local manifest differs from the last ready one.
try {
    $definitions->requireSynchronized();
    $teamId = $definitions->team('forecasting');

    echo "Readiness gate open; remote team id: {$teamId}\n";
} catch (DefinitionsNotSynchronizedException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
