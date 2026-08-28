<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\Console;

use Digitalygo\Envai\Sync\DefinitionsService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `envai:status`: Artisan alias of `envai definitions:status`.
 *
 * Delegates in-process to the core command, calling the container-bound
 * DefinitionsService: the connection, definitions path and state driver
 * come from config/envai.php, not from command line options. Exit code
 * 0 when synchronized, 1 otherwise (never synchronized, drifted or
 * definitions unavailable).
 */
final class EnvaiStatusCommand extends Command
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
        $this->setName('envai:status')
            ->setDescription('Reports the synchronization state of the connection against the local definitions (Laravel alias of envai definitions:status).')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a machine-readable JSON document instead of the text report.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CoreCommandRunner::status(
            $this->resolveDefinitions(),
            $input,
            $output,
            $this->definitionsPath,
            $this->resolveConnection(),
        );
    }
}
