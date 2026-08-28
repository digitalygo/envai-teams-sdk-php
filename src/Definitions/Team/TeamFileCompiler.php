<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Team;

use Digitalygo\Envai\Definitions\Validation\DefinitionViolation;

/**
 * Compiles every `*.php` file of the `envai/teams` directory into
 * TeamDefinition instances. Each file is included in an isolated scope
 * and must return a TeamBuilder; build() freezes it into an immutable
 * definition. The local key returned by the builder must match the file
 * name without the `.php` extension, otherwise a violation is reported.
 *
 * Content problems (a file that does not return a TeamBuilder, a builder
 * that cannot be built, a key/filename mismatch) are reported as
 * DefinitionViolation entries; only a missing directory is a structural
 * error.
 */
final class TeamFileCompiler
{
    /**
     * @return array{definitions: array<string, TeamDefinition>, violations: list<DefinitionViolation>}
     */
    public function compile(string $teamsDirectory): array
    {
        if (! is_dir($teamsDirectory)) {
            throw new \InvalidArgumentException(sprintf('Teams directory "%s" does not exist.', $teamsDirectory));
        }

        $files = glob($teamsDirectory.DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($files, SORT_STRING);

        $definitions = [];
        $violations = [];

        foreach ($files as $file) {
            $fileName = basename($file, '.php');

            try {
                $returned = (static function (string $path): mixed {
                    return include $path;
                })($file);
            } catch (\Throwable $exception) {
                $violations[] = new DefinitionViolation(
                    $fileName,
                    '',
                    'team.invalid_file',
                    sprintf('Team file "%s" cannot be loaded: %s', $fileName, $exception->getMessage()),
                );

                continue;
            }

            if (! $returned instanceof TeamBuilder) {
                $violations[] = new DefinitionViolation(
                    $fileName,
                    '',
                    'team.invalid_file',
                    sprintf('Team file "%s" must return a TeamBuilder, got %s.', $fileName, get_debug_type($returned)),
                );

                continue;
            }

            try {
                $team = $returned->build();
            } catch (\Throwable $exception) {
                $violations[] = new DefinitionViolation(
                    $fileName,
                    '',
                    'team.build_failed',
                    sprintf('Team file "%s" cannot be built: %s', $fileName, $exception->getMessage()),
                );

                continue;
            }

            if ($returned->localKey() !== $fileName) {
                $violations[] = new DefinitionViolation(
                    $fileName,
                    'local_key',
                    'team.key_filename_mismatch',
                    sprintf(
                        'Team file "%s" declares local key "%s"; the key must match the file name.',
                        $fileName,
                        $returned->localKey(),
                    ),
                );
            }

            $definitions[$team->localKey] = $team;
        }

        return ['definitions' => $definitions, 'violations' => $violations];
    }
}
