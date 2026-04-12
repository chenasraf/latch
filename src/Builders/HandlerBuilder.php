<?php

declare(strict_types=1);

namespace Latch\Builders;

use Latch\HookHandler;
use Latch\HookSource;

/**
 * Fluent builder for configuring and registering a handler.
 */
final class HandlerBuilder
{
    private int $priority = 10;

    private bool $exclusive = false;

    private ?\Closure $condition = null;

    /** @var list<string> */
    private array $tags = [];

    public function __construct(
        private readonly HookSource $source,
        private readonly string $point,
    ) {}

    public function priority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function exclusive(): self
    {
        $this->exclusive = true;

        return $this;
    }

    /**
     * @param  callable(object): bool  $condition
     */
    public function when(callable $condition): self
    {
        $this->condition = $condition(...);

        return $this;
    }

    public function tag(string ...$tags): self
    {
        $this->tags = [...$this->tags, ...array_values($tags)];

        return $this;
    }

    /**
     * Register the handler callable. This finalizes the builder.
     *
     * @param  callable  $handler  The handler function to register
     */
    public function handle(callable $handler): HookHandler
    {
        $hookHandler = new HookHandler(
            handler: $handler(...),
            priority: $this->priority,
            exclusive: $this->exclusive,
            condition: $this->condition,
            tags: $this->tags,
        );

        $this->source->addHandler($this->point, $hookHandler);

        return $hookHandler;
    }
}
