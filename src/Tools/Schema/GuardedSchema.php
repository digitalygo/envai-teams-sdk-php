<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Schema;

use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Info\SchemaInfo;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\ValidationContext;

/** @internal Prevent reference cycles that revisit the same schema/data location. */
final class GuardedSchema implements Schema
{
    /** @var array<string, true> */
    private array $active = [];

    public function __construct(private readonly Schema $schema) {}

    public function info(): SchemaInfo
    {
        return $this->schema->info();
    }

    public function validate(ValidationContext $context): ?ValidationError
    {
        $path = [];
        for ($cursor = $context; $cursor !== null; $cursor = $cursor->parent()) {
            $path = [...$cursor->currentDataPath(), ...$path];
        }
        $key = json_encode($path, JSON_THROW_ON_ERROR);
        if (isset($this->active[$key])) {
            throw new \InvalidArgumentException('Nonproductive schema reference cycle.');
        }
        $this->active[$key] = true;
        try {
            return $this->schema->validate($context);
        } finally {
            unset($this->active[$key]);
        }
    }
}
