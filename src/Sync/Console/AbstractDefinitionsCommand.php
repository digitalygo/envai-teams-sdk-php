<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Console;

use Digitalygo\Envai\Exceptions\DefinitionDriftException;
use Digitalygo\Envai\Support\ConsoleBootstrap;
use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\Planner\SyncAction;
use Digitalygo\Envai\Sync\Planner\SyncClassification;
use Digitalygo\Envai\Sync\Planner\SyncOperation;
use Digitalygo\Envai\Sync\Planner\SyncOptions;
use Digitalygo\Envai\Sync\Planner\SyncPlan;
use Digitalygo\Envai\Sync\Planner\SyncScope;
use Digitalygo\Envai\Sync\Reconciler\SyncReport;
use Digitalygo\Envai\Sync\Reconciler\SyncReportStatus;
use Digitalygo\Envai\Sync\State\ResourceType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared behavior of the `envai definitions:*` commands: option
 * resolution against the environment defaults, the definitions factory
 * closure (injectable in tests), the SyncOptions mapping and the
 * deterministic text rendering shared between plan and sync.
 */
abstract class AbstractDefinitionsCommand extends Command
{
    /**
     * @var \Closure(string, string, string, string): DefinitionsService
     */
    protected readonly \Closure $definitionsFactory;

    /**
     * @param  \Closure(string, string, string, string): DefinitionsService|null  $definitionsFactory
     */
    public function __construct(?\Closure $definitionsFactory = null)
    {
        $this->definitionsFactory = $definitionsFactory ?? ConsoleBootstrap::definitionsFactory();

        parent::__construct();
    }

    protected function connection(InputInterface $input): string
    {
        return $this->stringOption($input, 'connection', 'default');
    }

    protected function definitionsPath(InputInterface $input): string
    {
        return $this->stringOption($input, 'definitions-path', 'envai');
    }

    protected function stateDriver(InputInterface $input): string
    {
        return $this->stringOption($input, 'state-driver', 'file');
    }

    protected function stateFilePath(): string
    {
        return '.envai';
    }

    /**
     * The definitions service for the resolved command line options.
     */
    protected function service(InputInterface $input): DefinitionsService
    {
        return ($this->definitionsFactory)(
            $this->connection($input),
            $this->definitionsPath($input),
            $this->stateDriver($input),
            $this->stateFilePath(),
        );
    }

    /**
     * Maps the command line policy options onto a SyncOptions instance.
     * Repeated `--only` flags are allowed but must name the same kind:
     * the planner scope is single-kind.
     */
    protected function syncOptions(InputInterface $input): SyncOptions
    {
        return new SyncOptions(
            scope: $this->scope($input),
            adopt: (bool) $input->getOption('adopt'),
            overwriteDrift: (bool) $input->getOption('overwrite-drift'),
            recreateMissing: (bool) $input->getOption('recreate-missing'),
            prune: (bool) $input->getOption('prune'),
            failOnDrift: (bool) $input->getOption('fail-on-drift'),
            dryRun: (bool) $input->getOption('dry-run'),
        );
    }

    /**
     * The 24.1 plan text format: per-type sections of executable and
     * unchanged operations in plan order, then a Conflicts section with
     * one `!` line per blocking operation. Non-blocking skipped
     * operations (out of scope, prune disabled) are omitted from the text
     * but always present in the machine-readable --json shape.
     */
    protected function renderPlanText(SyncPlan $plan): string
    {
        $lines = [
            'Connection: '.$plan->connection,
            'Manifest:   '.$plan->manifestHash,
            '',
        ];

        $sections = [
            ResourceType::Tool->value => 'Tools',
            ResourceType::Agent->value => 'Agents',
            ResourceType::Team->value => 'Teams',
        ];

        foreach ($sections as $type => $title) {
            $rows = [];

            foreach ($plan->byResourceType(ResourceType::from($type)) as $op) {
                if ($op->blocking) {
                    continue;
                }

                if ($op->action === SyncAction::None && $op->classification !== SyncClassification::Unchanged) {
                    continue;
                }

                $rows[] = '  '.$this->symbol($op).' '.$op->localKey;
            }

            if ($rows !== []) {
                $lines[] = $title;
                $lines = [...$lines, ...$rows];
                $lines[] = '';
            }
        }

        $blocking = $plan->blocking();

        if ($blocking !== []) {
            $lines[] = 'Conflicts';

            foreach ($blocking as $op) {
                $lines[] = '  ! '.$op->localKey.': '.$this->humanizedReason($op);
            }

            $lines[] = '';
        }

        while ($lines !== [] && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    protected function renderReportText(SyncReport $report): string
    {
        return implode("\n", [
            'Connection: '.$report->connection,
            'Manifest:   '.$report->manifestHash,
            'Plan hash:  '.$report->planHash,
            'Status:     '.$report->status->value,
            'Results:    '.$this->outcomeCounts($report->summary),
            'Manifest ready: '.($report->manifestReady ? 'yes' : 'no'),
            'Next: '.$this->nextHint($report),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function writeJson(OutputInterface $output, array $data): void
    {
        $output->writeln((string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function isJson(InputInterface $input): bool
    {
        return (bool) $input->getOption('json');
    }

    protected function addConnectionOption(): static
    {
        return $this->addOption('connection', null, InputOption::VALUE_REQUIRED, 'Connection name (defaults to the ENVAI_CONNECTION or "default").');
    }

    protected function addDefinitionsPathOption(): static
    {
        return $this->addOption('definitions-path', null, InputOption::VALUE_REQUIRED, 'Path to the local envai/ definitions directory (defaults to ENVAI_DEFINITIONS_PATH or "envai").');
    }

    protected function addStateDriverOption(): static
    {
        return $this->addOption('state-driver', null, InputOption::VALUE_REQUIRED, 'Sync state store driver: inmemory, file or pdo (defaults to ENVAI_STATE_DRIVER or "file").');
    }

    protected function addPolicyOptions(): static
    {
        return $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Plan and report without mutating anything.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a machine-readable JSON document instead of the text report.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict the plan to one resource kind: tools, agents or teams (repeatable).')
            ->addOption('adopt', null, InputOption::VALUE_NONE, 'Adopt unmanaged remote resources with a matching name.')
            ->addOption('overwrite-drift', null, InputOption::VALUE_NONE, 'Overwrite remote resources that changed after the last apply.')
            ->addOption('recreate-missing', null, InputOption::VALUE_NONE, 'Recreate managed resources whose remote vanished.')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Delete managed resources no longer declared by the manifest.')
            ->addOption('fail-on-drift', null, InputOption::VALUE_NONE, 'Exit with code 2 when the plan contains drifted operations.');
    }

    /**
     * Renders a drift exception as an error and returns the exit code 2
     * contract of --fail-on-drift.
     */
    protected function handleDriftException(OutputInterface $output, DefinitionDriftException $exception): int
    {
        $output->writeln('<error>'.$exception->getMessage().'</error>');

        return 2;
    }

    protected function handleUnexpected(OutputInterface $output, \Throwable $exception): int
    {
        $output->writeln('<error>'.$exception->getMessage().'</error>');

        return 1;
    }

    private function scope(InputInterface $input): SyncScope
    {
        $only = $input->getOption('only');

        if (! is_array($only)) {
            return SyncScope::All;
        }

        $kinds = [];

        foreach ($only as $value) {
            if (! is_string($value)) {
                throw new \InvalidArgumentException('--only values must be strings.');
            }

            $kinds[] = strtolower($value);
        }

        $kinds = array_values(array_unique($kinds));

        if ($kinds === []) {
            return SyncScope::All;
        }

        if (count($kinds) > 1) {
            throw new \InvalidArgumentException('--only accepts a single resource kind (tools, agents or teams); the planner scope is single-kind.');
        }

        return match ($kinds[0]) {
            'tools' => SyncScope::Tools,
            'agents' => SyncScope::Agents,
            'teams' => SyncScope::Teams,
            default => throw new \InvalidArgumentException(sprintf(
                'Unknown --only value "%s"; expected tools, agents or teams.',
                $kinds[0],
            )),
        };
    }

    private function stringOption(InputInterface $input, string $name, string $default): string
    {
        $value = $input->getOption($name);

        if (! is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * The 24.1 operation symbol: + create/adopt/recreate, ~ update,
     * = unchanged, - delete.
     */
    private function symbol(SyncOperation $op): string
    {
        if ($op->classification === SyncClassification::Unchanged) {
            return '=';
        }

        return match ($op->action) {
            SyncAction::Create, SyncAction::Recreate, SyncAction::Adopt => '+',
            SyncAction::Update => '~',
            SyncAction::Delete => '-',
            SyncAction::None, SyncAction::Recover => '=',
        };
    }

    private function humanizedReason(SyncOperation $op): string
    {
        return match ($op->reasonCode) {
            'drift' => 'remote changed after last apply',
            'conflict' => 'desired and remote changed since the last apply',
            'adoption_required' => 'a remote with the same name exists but is unmanaged; adopt it explicitly',
            'missing_remote' => 'the managed remote is missing; recreate it explicitly',
            default => $op->reasonCode ?? 'blocked',
        };
    }

    /**
     * @param  array<string, int>  $summary
     */
    private function outcomeCounts(array $summary): string
    {
        $parts = [];

        foreach (['succeeded', 'skipped', 'pending', 'failed', 'ambiguous'] as $key) {
            $count = $summary[$key] ?? 0;

            if ($count > 0) {
                $parts[] = $count.' '.$key;
            }
        }

        return $parts === [] ? '0 total' : implode(', ', $parts);
    }

    private function nextHint(SyncReport $report): string
    {
        return match ($report->status) {
            SyncReportStatus::DryRun => 'run envai definitions:sync (without --dry-run) to apply the plan.',
            SyncReportStatus::Blocked => 'inspect the blocked operations with envai definitions:plan.',
            SyncReportStatus::Failed, SyncReportStatus::Partial => 'fix the reported errors and run envai definitions:sync again.',
            SyncReportStatus::Succeeded => $report->manifestReady
                ? 'the readiness gate is open for connection "'.$report->connection.'".'
                : 'the plan was partial (--only); run a full envai definitions:sync to mark the manifest ready.',
        };
    }
}
