<?php

declare(strict_types=1);

/*
 * Illustrative example: a declarative team definition.
 *
 * The key passed to Team::make() must match the file name. The
 * orchestrator and member arguments are local agent keys resolved by
 * the reconciler to remote agent ids; no remote id belongs in this file.
 */

use Digitalygo\Envai\Definitions\Team;

return Team::make('forecasting')
    ->name('Forecasting team')
    ->description('Researches and produces forecasts')
    ->orchestrator('orchestrator')
    ->member('researcher', role: 'research')
    ->member('forecaster', role: 'forecasting');
