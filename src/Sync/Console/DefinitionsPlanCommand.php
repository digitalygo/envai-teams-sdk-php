<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Console;

use Digitalygo\Envai\Exceptions\DefinitionDriftException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `envai definitions:plan`: builds the three-way plan of the current
 * definitions against the remote resources (project document sections
 * 19 and 24). The lock is acquired and released around the local +
 * remote phase; nothing is mutated.
 *
 * Text output follows section 24.1 exactly: the Connection and Manifest
 * header, per-type sections (Tools, Agents, Teams) with `+ create`,
 * `~ update`, `= unchanged`, `- delete` lines in plan order and a
 * Conflicts section with `!` lines for blocking operations. `--json`
 * emits SyncPlan::toArray() (plan_format 1).
 *
 * Exit codes: 0 when the plan has no blocking operations, 1 when it has
 * (or an error occurred), 2 when --fail-on-drift triggered the drift
 * exception. `--dry-run` is accepted for symmetry with sync but planning
 * is already read-only.
 */
final class DefinitionsPlanCommand extends AbstractDefinitionsCommand
{
    protected function configure(): void
    {
        $this->setName('definitions:plan')
            ->setDescription('Builds the three-way sync plan without mutating anything.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit the plan as a stable JSON document.');

        $this->addConnectionOption()
            ->addDefinitionsPathOption()
            ->addStateDriverOption()
            ->addPolicyOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $this->definitionsPath($input);

        if (! is_dir($path)) {
            $output->writeln('<error>The definitions directory "'.$path.'" does not exist; run from the project root or pass --definitions-path.</error>');

            return 2;
        }

        try {
            $plan = $this->service($input)->plan($this->syncOptions($input));
        } catch (DefinitionDriftException $exception) {
            return $this->handleDriftException($output, $exception);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return 2;
        } catch (\Throwable $exception) {
            return $this->handleUnexpected($output, $exception);
        }

        if ($this->isJson($input)) {
            $this->writeJson($output, $plan->toArray());
        } else {
            $output->writeln($this->renderPlanText($plan));
        }

        return $plan->blocking() === [] ? 0 : 1;
    }
}
