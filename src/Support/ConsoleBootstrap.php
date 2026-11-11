<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Support;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Sync\DefinitionsService;

/**
 * CLI bootstrap: builds an EnvaiClient from environment variables so
 * `bin/envai` needs no configuration file.
 *
 * Variables:
 *
 * - ENVAI_BASE_URL: the API origin (mandatory);
 * - ENVAI_API_KEY: the API key (mandatory, wrapped in a SensitiveString);
 * - ENVAI_CONNECTION: connection name, default `default`;
 * - ENVAI_DEFINITIONS_PATH: local definitions directory, default `envai`;
 * - ENVAI_STATE_DRIVER: state store driver, default `file`;
 * - ENVAI_STRICT_VALIDATION: canonical HTTP validation, default `false`;
 * - ENVAI_STATE_PATH: state store file path, default `.envai`.
 *
 * The commands receive a factory closure (see definitionsFactory()) so
 * tests can inject a fake wiring; the production factory rebuilds the
 * client with the command line overrides baked into the config and
 * returns the definitions facade through the normal client wiring.
 */
final class ConsoleBootstrap
{
    private function __construct() {}

    /**
     * The configuration assembled from the environment. Missing mandatory
     * variables throw a RuntimeException with a clear message.
     */
    public static function config(): EnvaiConfig
    {
        return new EnvaiConfig(
            baseUrl: self::required('ENVAI_BASE_URL'),
            apiKey: new SensitiveString(self::required('ENVAI_API_KEY')),
            connectionName: self::env('ENVAI_CONNECTION', 'default'),
            definitionsPath: self::env('ENVAI_DEFINITIONS_PATH', 'envai'),
            stateDriver: self::env('ENVAI_STATE_DRIVER', 'file'),
            stateFilePath: self::env('ENVAI_STATE_PATH', '.envai'),
            strictValidation: self::strictValidation(),
        );
    }

    /**
     * A client built from the environment.
     */
    public static function client(): EnvaiClient
    {
        return new EnvaiClient(self::config());
    }

    /**
     * The production definitions factory for the CLI commands. The
     * closure receives the resolved command line options (connection,
     * definitions path, state driver and state file path) and returns the
     * definitions facade wired over a client whose config carries those
     * overrides, so `--connection`, `--definitions-path` and
     * `--state-driver` take precedence over the environment.
     *
     * @return \Closure(string, string, string, string): DefinitionsService
     */
    public static function definitionsFactory(): \Closure
    {
        return static function (string $connection, string $definitionsPath, string $stateDriver, string $stateFilePath): DefinitionsService {
            $base = self::config();

            $client = new EnvaiClient(new EnvaiConfig(
                baseUrl: $base->baseUrl,
                apiKey: $base->apiKey,
                jwksUrl: $base->jwksUrl,
                strictValidation: $base->strictValidation,
                connectionName: $connection,
                connectTimeoutSeconds: $base->connectTimeoutSeconds,
                requestTimeoutSeconds: $base->requestTimeoutSeconds,
                streamTimeoutSeconds: $base->streamTimeoutSeconds,
                headers: $base->headers,
                retryPolicy: $base->retryPolicy,
                definitionsPath: $definitionsPath,
                stateDriver: $stateDriver,
                stateFilePath: $stateFilePath,
            ));

            return $client->definitions($definitionsPath, $connection);
        };
    }

    private static function strictValidation(): bool
    {
        return match (strtolower(self::env('ENVAI_STRICT_VALIDATION', 'false'))) {
            'true', '1' => true,
            'false', '0' => false,
            default => throw new \RuntimeException('ENVAI_STRICT_VALIDATION must be true, false, 1, or 0.'),
        };
    }

    private static function required(string $name): string
    {
        $value = self::env($name, '');

        if ($value === '') {
            throw new \RuntimeException(sprintf(
                'Missing required environment variable %s; export it before running the CLI.',
                $name,
            ));
        }

        return $value;
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : $value;
    }
}
