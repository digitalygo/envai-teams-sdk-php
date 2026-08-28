<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions;

use Digitalygo\Envai\Definitions\Team\TeamBuilder;

/**
 * Facade for declaring teams in the `envai/teams` directory, matching the
 * `use Digitalygo\Envai\Definitions\Team;` import shown in the project
 * document:
 *
 *     return Team::make('forecasting')
 *         ->name('Forecasting team')
 *         ->orchestrator('orchestrator')
 *         ->member('researcher', role: 'research');
 *
 * The canonical implementation lives in the Digitalygo\Envai\Definitions
 * \Team namespace; this class exists so the documented import resolves.
 */
final class Team
{
    public static function make(string $localKey): TeamBuilder
    {
        return Team\Team::make($localKey);
    }
}
