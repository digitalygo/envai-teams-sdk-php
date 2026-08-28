<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Console;

use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ReadyManifest;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `envai definitions:state`: dumps the raw sync state store content for
 * the connection: the last manifest marked ready and every managed
 * resource with its stored identities, marker, desired hash and status.
 *
 * The document is redacted defensively: any field whose name contains
 * `token`, `key` or `secret` is masked with `***` (the structural
 * `local_key` identity is preserved), so credentials can never leak into
 * the dump. `--json` prints the redacted document; the default output is
 * a table. The command works without a definitions directory.
 */
final class DefinitionsStateCommand extends AbstractDefinitionsCommand
{
    private const REDACT_PATTERN = '/token|secret|key/i';

    protected function configure(): void
    {
        $this->setName('definitions:state')
            ->setDescription('Dumps the raw (redacted) sync state store content for the connection.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the redacted state document as JSON instead of a table.');

        $this->addConnectionOption()
            ->addDefinitionsPathOption()
            ->addStateDriverOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->connection($input);
        $service = $this->stateService($input);

        /** @var list<array<string, mixed>> $resources */
        $resources = array_map(
            static fn (ManagedResourceState $state): array => [
                'type' => $state->type->value,
                'local_key' => $state->localKey,
                'remote_id' => $state->remoteId,
                'remote_name' => $state->remoteName,
                'desired_hash' => $state->desiredHash,
                'remote_marker' => $state->remoteMarker,
                'status' => $state->status->value,
                'synchronized_at' => $state->synchronizedAt?->format(\DateTimeInterface::ATOM),
                'metadata' => $state->metadata,
            ],
            array_values(iterator_to_array($service->resources())),
        );

        $document = [
            'state_format' => 1,
            'connection' => $connection,
            'manifest' => self::manifestEntry($service->readyManifest()),
            'resources' => $resources,
        ];

        $redacted = self::redact($document);

        if ($this->isJson($input)) {
            $this->writeJson($output, $redacted);

            return 0;
        }

        $ready = $service->readyManifest();

        $output->writeln('Connection: '.$connection);
        $output->writeln('Manifest:   '.($ready === null ? 'none' : $ready->manifestHash.' (synchronized '.$ready->synchronizedAt->format(\DateTimeInterface::ATOM).')'));

        $table = new Table($output);
        $table->setHeaders(['Type', 'Local key', 'Remote id', 'Remote name', 'Status', 'Marker', 'Desired hash', 'Synchronized at']);

        foreach ($resources as $resource) {
            $table->addRow([
                $resource['type'],
                $resource['local_key'],
                $resource['remote_id'] ?? '-',
                $resource['remote_name'] ?? '-',
                $resource['status'],
                is_scalar($resource['remote_marker']) ? (string) $resource['remote_marker'] : '-',
                $resource['desired_hash'] ?? '-',
                $resource['synchronized_at'] ?? '-',
            ]);
        }

        $table->render();

        return 0;
    }

    /**
     * The state command accepts --definitions-path for uniformity but the
     * dump is store-only: the path is never compiled.
     */
    private function stateService(InputInterface $input): DefinitionsService
    {
        return ($this->definitionsFactory)(
            $this->connection($input),
            $this->definitionsPath($input),
            $this->stateDriver($input),
            $this->stateFilePath(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function manifestEntry(?ReadyManifest $ready): ?array
    {
        if ($ready === null) {
            return null;
        }

        return [
            'manifest_hash' => $ready->manifestHash,
            'format_version' => $ready->formatVersion,
            'synchronized_at' => $ready->synchronizedAt->format(\DateTimeInterface::ATOM),
            'metadata' => $ready->metadata,
        ];
    }

    /**
     * Recursively masks any field whose name contains `token`, `key` or
     * `secret` (case-insensitive), preserving the structural `local_key`
     * identity which is never a credential. Masked values are replaced
     * with `***` and never recursed into; list indexes are preserved.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private static function redact(array $document): array
    {
        $result = [];

        foreach ($document as $key => $item) {
            if ($key !== 'local_key' && preg_match(self::REDACT_PATTERN, $key) === 1) {
                $result[$key] = '***';

                continue;
            }

            $result[$key] = self::redactValue($item);
        }

        return $result;
    }

    /**
     * Redacts a nested value: arrays are recursed into preserving
     * integer list indexes, everything else is passed through.
     */
    private static function redactValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && $key !== 'local_key' && preg_match(self::REDACT_PATTERN, $key) === 1) {
                $result[$key] = '***';

                continue;
            }

            $result[$key] = self::redactValue($item);
        }

        return $result;
    }
}
