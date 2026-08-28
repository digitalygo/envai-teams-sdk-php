<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Console;

use Digitalygo\Envai\Definitions\Manifest\CanonicalManifestHasher;
use Digitalygo\Envai\Definitions\Manifest\DefinitionManifest;
use Digitalygo\Envai\Sync\Planner\PayloadNormalizer;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ResourceType;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `envai definitions:status`: compares the compiled current definitions
 * hash with the last manifest marked ready and reports the per-resource
 * state of the connection (key, store status, remote id, drift
 * indicator).
 *
 * The drift indicator is local-only: `ok` when the stored desired hash
 * of a resource still matches the currently compiled desired hash,
 * `changed` when the definition moved since the last apply and `n/a`
 * when the definitions cannot be compiled (for example when the
 * directory is absent, which the command tolerates).
 *
 * Exit code: 0 when synchronized (current hash equals the ready
 * manifest), 1 otherwise (never synchronized, drifted or definitions
 * unavailable).
 */
final class DefinitionsStatusCommand extends AbstractDefinitionsCommand
{
    protected function configure(): void
    {
        $this->setName('definitions:status')
            ->setDescription('Reports the synchronization state of the connection against the local definitions.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a machine-readable JSON document instead of the text report.');

        $this->addConnectionOption()
            ->addDefinitionsPathOption()
            ->addStateDriverOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->connection($input);
        $service = $this->service($input);

        $ready = $service->readyManifest();
        $currentHash = null;
        $manifest = null;

        try {
            $manifest = $service->manifest();
            $currentHash = $manifest->hash;
        } catch (\Throwable) {
            // The definitions directory is absent or unreadable: the
            // command still reports the store state and exits 1.
        }

        $synchronized = $currentHash !== null
            && $ready !== null
            && $ready->manifestHash === $currentHash;

        $resources = array_values(iterator_to_array($service->resources()));
        $desiredHashes = $manifest === null ? [] : $this->desiredHashes($manifest, $resources);

        $rows = [];

        foreach ($resources as $state) {
            $resourceKey = $state->type->value.':'.$state->localKey;

            $rows[] = [
                'key' => $resourceKey,
                'type' => $state->type->value,
                'local_key' => $state->localKey,
                'status' => $state->status->value,
                'remote_id' => $state->remoteId,
                'drift' => $manifest === null
                    ? 'n/a'
                    : (($desiredHashes[$resourceKey] ?? null) === $state->desiredHash ? 'ok' : 'changed'),
            ];
        }

        if ($this->isJson($input)) {
            $this->writeJson($output, [
                'status_format' => 1,
                'connection' => $connection,
                'manifest_hash' => $currentHash,
                'ready_manifest_hash' => $ready?->manifestHash,
                'synchronized' => $synchronized,
                'resources' => $rows,
            ]);

            return $synchronized ? 0 : 1;
        }

        $lines = [
            'Connection: '.$connection,
            'Manifest:   '.($currentHash ?? 'unavailable'),
            'Ready:      '.($ready === null ? 'none' : $ready->manifestHash.' (synchronized '.$ready->synchronizedAt->format(\DateTimeInterface::ATOM).')'),
            'Synchronized: '.($synchronized ? 'yes' : 'no'),
            '',
            'Resources',
        ];

        foreach ($rows as $resource) {
            $lines[] = sprintf(
                '  %-26s %-10s %-24s %s',
                $resource['key'],
                $resource['status'],
                $resource['remote_id'] ?? '-',
                $resource['drift'],
            );
        }

        $output->writeln($lines);

        return $synchronized ? 0 : 1;
    }

    /**
     * Recomputes the desired hash per declared resource exactly like the
     * planner does, so a resource whose definition changed since the last
     * apply reports `changed`.
     *
     * @param  list<ManagedResourceState>  $resources
     * @return array<string, string> Keyed by `<type>:<localKey>`.
     */
    private function desiredHashes(DefinitionManifest $manifest, array $resources): array
    {
        $agentIdToLocalKey = [];

        foreach ($resources as $state) {
            if ($state->type === ResourceType::Agent && $state->remoteId !== null) {
                $agentIdToLocalKey[$state->remoteId] = $state->localKey;
            }
        }

        $hasher = new CanonicalManifestHasher;
        $hashes = [];

        foreach ($manifest->tools as $localKey => $tool) {
            $hashes['tool:'.$localKey] = $hasher->resourceHash(
                'tool',
                $localKey,
                PayloadNormalizer::normalize(ResourceType::Tool, $tool->toRemotePayload()),
            );
        }

        foreach ($manifest->agents as $localKey => $agent) {
            $hashes['agent:'.$localKey] = $hasher->resourceHash(
                'agent',
                $localKey,
                PayloadNormalizer::normalize(ResourceType::Agent, $agent->toRemotePayload()),
            );
        }

        foreach ($manifest->teams as $localKey => $team) {
            $identity = [$team->orchestratorKey => $team->orchestratorKey];

            foreach ($team->members as $member) {
                $identity[$member->localAgentKey] = $member->localAgentKey;
            }

            $hashes['team:'.$localKey] = $hasher->resourceHash(
                'team',
                $localKey,
                PayloadNormalizer::normalize(ResourceType::Team, $team->toRemotePayload($identity, []), $agentIdToLocalKey),
            );
        }

        return $hashes;
    }
}
