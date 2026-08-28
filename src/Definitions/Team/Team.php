<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Team;

/**
 * Facade for declaring teams in the `envai/teams` directory:
 *
 *     return Team::make('forecasting')
 *         ->name('Forecasting team')
 *         ->orchestrator('orchestrator')
 *         ->member('researcher', role: 'research');
 *
 * The local key passed to make() must coincide with the file name
 * without the `.php` extension. A compatibility facade under the
 * Digitalygo\Envai\Definitions namespace honors the documented
 * `use Digitalygo\Envai\Definitions\Team;` import.
 */
final class Team
{
    public static function make(string $localKey): TeamBuilder
    {
        return TeamBuilder::make($localKey);
    }
}
