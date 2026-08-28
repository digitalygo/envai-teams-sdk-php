<?php

declare(strict_types=1);

/*
 * Global helper stubs for the EnvaiServiceProviderTest.
 *
 * The provider's register() merges the package config file, which calls
 * the base_path()/config_path()/database_path() helpers of
 * Illuminate\Foundation. The provider tests run on a bare Illuminate
 * Container without the foundation package, so these global functions
 * are stubbed here when nothing else defined them. In a real Laravel
 * application the foundation helpers already exist and the stubs are
 * skipped.
 *
 * The file deliberately has NO namespace: the stubs must be the global
 * functions the config file calls.
 */

if (! function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return sys_get_temp_dir().($path === '' ? '' : DIRECTORY_SEPARATOR.$path);
    }
}

if (! function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return sys_get_temp_dir().($path === '' ? '' : DIRECTORY_SEPARATOR.$path);
    }
}

if (! function_exists('database_path')) {
    function database_path(string $path = ''): string
    {
        return sys_get_temp_dir().($path === '' ? '' : DIRECTORY_SEPARATOR.$path);
    }
}
