<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\Console;

use Digitalygo\Envai\Sync\Console\DefinitionsPlanCommand;
use Digitalygo\Envai\Sync\Console\DefinitionsStateCommand;
use Digitalygo\Envai\Sync\Console\DefinitionsStatusCommand;
use Digitalygo\Envai\Sync\Console\DefinitionsSyncCommand;
use Digitalygo\Envai\Sync\Console\DefinitionsValidateCommand;
use Digitalygo\Envai\Sync\DefinitionsService;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * In-process delegation from the Laravel Artisan aliases to the core
 * `envai definitions:*` commands.
 *
 * Each alias maps its Symfony options onto a fresh ArrayInput carrying
 * exactly the option names the core command declares (the core input is
 * never the shared command input, so application-level options such as
 * `--no-interaction` cannot leak into the re-parse), and runs the core
 * command with a factory that returns the container-bound
 * DefinitionsService regardless of the CLI connection/path/driver
 * options: those are configured through config/envai.php in the Laravel
 * integration, never per invocation.
 *
 * The core command owns the option mapping, the text/JSON rendering and
 * the exit codes, so the aliases stay thin and faithful to the
 * standalone CLI behavior.
 */
final class CoreCommandRunner
{
    private const POLICY_OPTIONS = [
        'dry-run',
        'json',
        'only',
        'adopt',
        'overwrite-drift',
        'recreate-missing',
        'prune',
        'fail-on-drift',
    ];

    private function __construct() {}

    public static function validate(DefinitionsService $definitions, InputInterface $input, OutputInterface $output, ?string $definitionsPath = null, ?string $connection = null): int
    {
        return (new DefinitionsValidateCommand(self::factory($definitions)))
            ->run(self::input($input, ['json'], $definitionsPath, $connection), $output);
    }

    public static function plan(DefinitionsService $definitions, InputInterface $input, OutputInterface $output, ?string $definitionsPath = null, ?string $connection = null): int
    {
        return (new DefinitionsPlanCommand(self::factory($definitions)))
            ->run(self::input($input, self::POLICY_OPTIONS, $definitionsPath, $connection), $output);
    }

    public static function sync(DefinitionsService $definitions, InputInterface $input, OutputInterface $output, ?string $definitionsPath = null, ?string $connection = null): int
    {
        return (new DefinitionsSyncCommand(self::factory($definitions)))
            ->run(self::input($input, self::POLICY_OPTIONS, $definitionsPath, $connection), $output);
    }

    public static function status(DefinitionsService $definitions, InputInterface $input, OutputInterface $output, ?string $definitionsPath = null, ?string $connection = null): int
    {
        return (new DefinitionsStatusCommand(self::factory($definitions)))
            ->run(self::input($input, ['json'], $definitionsPath, $connection), $output);
    }

    public static function state(DefinitionsService $definitions, InputInterface $input, OutputInterface $output, ?string $definitionsPath = null, ?string $connection = null): int
    {
        return (new DefinitionsStateCommand(self::factory($definitions)))
            ->run(self::input($input, ['json'], $definitionsPath, $connection), $output);
    }

    /**
     * The core command factory: the container-bound DefinitionsService
     * is used no matter what connection/path/driver the core command
     * resolved from its options (the aliases never declare those
     * options; config/envai.php owns them).
     *
     * @return \Closure(string, string, string, string): DefinitionsService
     */
    private static function factory(DefinitionsService $definitions): \Closure
    {
        return static fn (string $connection, string $definitionsPath, string $stateDriver, string $stateFilePath): DefinitionsService => $definitions;
    }

    /**
     * Builds the core command input from the alias input, reading only
     * the option names the alias actually declared. The definitions path
     * and connection are injected when known (the container-configured
     * values) so the core command's directory checks and rendered
     * headers see the configured layout and connection.
     *
     * @param  list<string>  $optionNames
     */
    private static function input(InputInterface $input, array $optionNames, ?string $definitionsPath, ?string $connection): ArrayInput
    {
        $values = [];

        foreach ($optionNames as $name) {
            if ($name === 'only') {
                $only = $input->getOption('only');

                $values['--only'] = is_array($only) ? $only : [];

                continue;
            }

            $values['--'.$name] = (bool) $input->getOption($name);
        }

        if ($definitionsPath !== null) {
            $values['--definitions-path'] = $definitionsPath;
        }

        if ($connection !== null && $connection !== '') {
            $values['--connection'] = $connection;
        }

        return new ArrayInput($values);
    }
}
