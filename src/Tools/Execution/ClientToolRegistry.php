<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Execution;

use Digitalygo\Envai\Tools\Contracts\ClientTool;

/**
 * Immutable map of client tool names to their runtime configuration,
 * mirroring the `envai/tools.php` registry shape.
 *
 * fromArray() accepts either a handler class name (default timeout 120
 * seconds) or `['class' => ..., 'timeout_seconds' => ...]`; the timeout is
 * clamped to the platform range 1..600. Entries whose class does not exist
 * or does not implement ClientTool are rejected so wiring errors surface
 * early.
 */
final class ClientToolRegistry
{
    private const DEFAULT_TIMEOUT_SECONDS = 120;

    private const MIN_TIMEOUT_SECONDS = 1;

    private const MAX_TIMEOUT_SECONDS = 600;

    /**
     * @var array<string, RegisteredTool>
     */
    private readonly array $tools;

    /**
     * @param  array<string, RegisteredTool>  $tools
     */
    private function __construct(array $tools)
    {
        $this->tools = $tools;
    }

    /**
     * @param  array<string, mixed>  $registry  tools.php shaped registry.
     */
    public static function fromArray(array $registry): self
    {
        $tools = [];

        foreach ($registry as $name => $entry) {
            if ($name === '') {
                throw new \InvalidArgumentException('Client tool registry keys must be non-empty strings.');
            }

            if (is_string($entry)) {
                $class = $entry;
                $timeout = self::DEFAULT_TIMEOUT_SECONDS;
            } elseif (is_array($entry)) {
                $class = $entry['class'] ?? null;
                $timeout = $entry['timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS;

                if (! is_int($timeout)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Client tool "%s" timeout_seconds must be an integer.',
                        $name,
                    ));
                }
            } else {
                throw new \InvalidArgumentException(sprintf(
                    'Client tool "%s" must be a handler class name or an array with a "class" key.',
                    $name,
                ));
            }

            if (! is_string($class) || $class === '' || ! is_subclass_of($class, ClientTool::class)) {
                throw new \InvalidArgumentException(sprintf(
                    'Client tool "%s" class "%s" must exist and implement %s.',
                    $name,
                    is_string($class) ? $class : get_debug_type($class),
                    ClientTool::class,
                ));
            }

            $tools[$name] = new RegisteredTool($name, $class, self::clampTimeout($timeout));
        }

        return new self($tools);
    }

    public function lookup(string $name): ?RegisteredTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->tools);
    }

    private static function clampTimeout(int $timeout): int
    {
        return max(self::MIN_TIMEOUT_SECONDS, min(self::MAX_TIMEOUT_SECONDS, $timeout));
    }
}
