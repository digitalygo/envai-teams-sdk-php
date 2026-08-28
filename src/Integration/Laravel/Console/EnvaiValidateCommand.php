<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\Console;

use Digitalygo\Envai\Sync\DefinitionsService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `envai:validate`: Artisan alias of `envai definitions:validate`.
 *
 * Delegates in-process to the core command, calling the container-bound
 * DefinitionsService: the connection, definitions path and state driver
 * come from config/envai.php, not from command line options. Exit codes
 * mirror the core command (0 valid, 1 violations, 2 unusable layout).
 */
final class EnvaiValidateCommand extends Command
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
        $this->setName('envai:validate')
            ->setDescription('Compiles and validates the local definitions (local phase only; Laravel alias of envai definitions:validate).')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a machine-readable JSON document instead of the text report.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CoreCommandRunner::validate(
            $this->resolveDefinitions(),
            $input,
            $output,
            $this->definitionsPath,
            $this->resolveConnection(),
        );
    }
}
