<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\Console;

use Digitalygo\Envai\Sync\DefinitionsService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `envai:sync`: Artisan alias of `envai definitions:sync`.
 *
 * Delegates in-process to the core command, calling the container-bound
 * DefinitionsService: the connection, definitions path and state driver
 * come from config/envai.php, not from command line options. The policy
 * options (--dry-run, --json, --only, --adopt, --overwrite-drift,
 * --recreate-missing, --prune, --fail-on-drift) map onto the reconciler
 * SyncOptions exactly like the standalone CLI. Exit codes mirror the
 * core command (0 succeeded or dry run, 1 blocked/failed/partial, 2
 * --fail-on-drift triggered).
 */
final class EnvaiSyncCommand extends Command
{
    use ResolvesDefinitionsService;

    public function __construct(
        private readonly ?DefinitionsService $definitions = null,
        private readonly ?string $definitionsPath = null,
        private readonly ?string $connection = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('envai:sync')
            ->setDescription('Plans and applies the definitions sync, marking the manifest ready on full success (Laravel alias of envai definitions:sync).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Plan and report without mutating anything.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a machine-readable JSON document instead of the text report.')
            ->addOption('only', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict the plan to one resource kind: tools, agents or teams (repeatable).')
            ->addOption('adopt', null, InputOption::VALUE_NONE, 'Adopt unmanaged remote resources with a matching name.')
            ->addOption('overwrite-drift', null, InputOption::VALUE_NONE, 'Overwrite remote resources that changed after the last apply.')
            ->addOption('recreate-missing', null, InputOption::VALUE_NONE, 'Recreate managed resources whose remote vanished.')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Delete managed resources no longer declared by the manifest.')
            ->addOption('fail-on-drift', null, InputOption::VALUE_NONE, 'Exit with code 2 when the plan contains drifted operations.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CoreCommandRunner::sync(
            $this->resolveDefinitions(),
            $input,
            $output,
            $this->definitionsPath,
            $this->resolveConnection(),
        );
    }
}
