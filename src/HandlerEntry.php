<?php

declare(strict_types=1);

namespace Latch;

/**
 * A registered handler for a hook point.
 */
final class HandlerEntry
{
    /**
     * @param  \Closure  $handler  The handler callable
     * @param  int  $priority  Lower runs first
     * @param  bool  $exclusive  If true, short-circuits remaining handlers after this one
     * @param  \Closure|null  $condition  When set, handler is skipped if condition returns false
     * @param  list<string>  $tags  Arbitrary tags for introspection
     */
    public function __construct(
        public readonly \Closure $handler,
        public readonly int $priority = 10,
        public readonly bool $exclusive = false,
        public readonly ?\Closure $condition = null,
        public readonly array $tags = [],
    ) {}

    /**
     * Check whether this handler should run for the given payload/context.
     */
    public function shouldRun(?object $context = null): bool
    {
        if ($this->condition === null) {
            return true;
        }

        return (bool) ($this->condition)($context);
    }
}
