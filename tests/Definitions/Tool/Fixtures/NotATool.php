<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Tool\Fixtures;

/**
 * Plain class that does not implement ClientTool, used to verify that
 * the registry rejects non-conforming handlers.
 */
final class NotATool {}
