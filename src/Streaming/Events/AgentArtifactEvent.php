<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * An artifact produced by the agent (for example a markdown file).
 */
final readonly class AgentArtifactEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $artifactId,
        public string $name,
        public string $mediaType,
        public int $size,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.artifact';
    }
}
