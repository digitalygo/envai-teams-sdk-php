<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Console;

use Digitalygo\Envai\Definitions\Validation\DefinitionViolation;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `envai definitions:validate`: compiles and validates the local
 * definitions locally: no lock, remote reads or writes.
 *
 * Text report:
 *
 *     Connection: production
 *     Definitions: envai
 *     Manifest:   sha256:...
 *     Valid: yes
 *
 * With violations each one is listed on its own `!` line and the exit
 * code is 1. `--json` emits a stable document. The definitions directory
 * is required: a missing or structurally invalid layout exits 2 with a
 * clear error.
 */
final class DefinitionsValidateCommand extends AbstractDefinitionsCommand
{
    protected function configure(): void
    {
        $this->setName('definitions:validate')
            ->setDescription('Compiles and validates the local definitions (local phase only).')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a machine-readable JSON document instead of the text report.');

        $this->addConnectionOption()
            ->addDefinitionsPathOption()
            ->addStateDriverOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->definitionsPath($input);

        if (! is_dir($path)) {
            $output->writeln('<error>The definitions directory "'.$path.'" does not exist; run from the project root or pass --definitions-path.</error>');

            return 2;
        }

        try {
            $result = $this->service($input)->validate();
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return 2;
        } catch (\Throwable $exception) {
            return $this->handleUnexpected($output, $exception);
        }

        if ($this->isJson($input)) {
            $this->writeJson($output, [
                'validate_format' => 1,
                'connection' => $this->connection($input),
                'definitions_path' => $path,
                'manifest_hash' => $result->manifest->hash,
                'valid' => $result->valid,
                'violations' => array_map(static fn (DefinitionViolation $violation): array => [
                    'source' => $violation->sourceKey,
                    'field' => $violation->fieldPath,
                    'code' => $violation->code,
                    'message' => $violation->message,
                ], $result->violations),
            ]);

            return $result->valid ? 0 : 1;
        }

        $lines = [
            'Connection: '.$this->connection($input),
            'Definitions: '.$path,
            'Manifest:   '.$result->manifest->hash,
            'Valid: '.($result->valid ? 'yes' : 'no'),
        ];

        if ($result->violations !== []) {
            $lines[] = 'Violations: '.count($result->violations);

            foreach ($result->violations as $violation) {
                $lines[] = '  ! '.$violation->sourceKey.' ('.$violation->fieldPath.') '.$violation->code.': '.$violation->message;
            }
        }

        $output->writeln($lines);

        return $result->valid ? 0 : 1;
    }
}
