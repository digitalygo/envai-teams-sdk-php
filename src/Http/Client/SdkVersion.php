<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Client;

/**
 * SDK version information.
 *
 * The composer package does not pin a "version" field (the version comes from
 * git tags), so this constant is the single in-code source of truth for the
 * release version. Keep VERSION in sync with the composer.json package
 * version and the git tag of each release.
 */
final class SdkVersion
{
    /**
     * Current SDK version. Keep in sync with composer.json and the release tag.
     */
    public const VERSION = '0.1.0';

    private function __construct() {}
}
