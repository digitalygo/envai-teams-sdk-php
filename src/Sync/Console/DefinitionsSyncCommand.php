<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Console;

use Digitalygo\Envai\Exceptions\DefinitionDriftException;
use Digitalygo\Envai\Sync\Reconciler\SyncReportStatus;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `envai definitions:sync`: plans and applies under a single lock
 * acquisition, updating the state store and marking the manifest ready
 * only when the scope is complete and nothing was blocked, failed or
 * ambiguous (project document sections 20.3, 20.4 and 24).
 *
 * Text summary: outcome counts per result, the `Manifest ready: yes|no`
 * line and a next-action hint. `--json` emits SyncReport::toArray()
 * (report_format 1). `--dry-run` plans and reports without mutating
 * anything.
 *
 * Exit codes: 0 succeeded or dry run, 1 blocked/failed/partial, 2 when
 * --fail-on-drift triggered the drift exception.
 */
final class DefinitionsSyncCommand extends AbstractDefinitionsCommand
{
    protected function configure(): void
    {
        $this->setName('definitions:sync')
            ->setDescription('Plans and applies the definitions sync, marking the manifest ready on full success.');

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
            $report = $this->service($input)->apply($this->syncOptions($input));
        } catch (DefinitionDriftException $exception) {
            return $this->handleDriftException($output, $exception);
        } catch (\InvalidArgumentException $exception) {
            $output->writeln('<error>'.$exception->getMessage().'</error>');

            return 2;
        } catch (\Throwable $exception) {
            return $this->handleUnexpected($output, $exception);
        }

        if ($this->isJson($input)) {
            $this->writeJson($output, $report->toArray());
        } else {
            $output->writeln($this->renderReportText($report));
        }

        return match ($report->status) {
            SyncReportStatus::Succeeded, SyncReportStatus::DryRun => 0,
            SyncReportStatus::Blocked, SyncReportStatus::Failed, SyncReportStatus::Partial => 1,
        };
    }
}
