<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\Console;

use Digitalygo\Envai\Sync\DefinitionsService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `envai:state`: Artisan alias of `envai definitions:state`.
 *
 * Delegates in-process to the core command, calling the container-bound
 * DefinitionsService: the connection, definitions path and state driver
 * come from config/envai.php, not from command line options. The dump is
 * store-only (the definitions are never compiled) and redacted
 * defensively; exit code is always 0.
 */
final class EnvaiStateCommand extends Command
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
        $this->setName('envai:state')
            ->setDescription('Dumps the raw (redacted) sync state store content for the connection (Laravel alias of envai definitions:state).')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the redacted state document as JSON instead of a table.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return CoreCommandRunner::state(
            $this->resolveDefinitions(),
            $input,
            $output,
            $this->definitionsPath,
            $this->resolveConnection(),
        );
    }
}
