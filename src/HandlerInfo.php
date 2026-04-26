<?php

declare(strict_types=1);

namespace Latch;

/**
 * Metadata about a registered handler, used for capability discovery.
 */
final class HandlerInfo
{
    /**
     * @param  string  $name  The unique handler name
     * @param  list<string>  $tags  Capability tags declared at registration (excludes handler:{name} auto-tag)
     */
    public function __construct(
        public readonly string $name,
        public readonly array $tags = [],
    ) {}

    /**
     * Check whether this handler has a specific capability tag.
     */
    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, true);
    }
}
