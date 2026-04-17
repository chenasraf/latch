<?php

declare(strict_types=1);

namespace Latch;

use Latch\Builders\HandlerBuilder;
use Latch\Contracts\HookRegistryInterface;

/**
 * A named handler scope that auto-tags all hooks with handler:{name} and any additional tags.
 */
final class RegisteredHandler
{
    /** @var list<string> */
    private array $autoTags;

    /**
     * @param  list<string>  $tags  Additional tags applied to all hooks from this handler
     */
    public function __construct(
        private readonly HookRegistryInterface $registry,
        public readonly string $name,
        array $tags = [],
    ) {
        $this->autoTags = ["handler:{$this->name}", ...$tags];
    }

    /**
     * Add additional tags to all future hooks from this handler.
     */
    public function globalTags(string ...$tags): self
    {
        $this->autoTags = [...$this->autoTags, ...array_values($tags)];

        return $this;
    }

    public function hook(string $sourceId, string $point): HandlerBuilder
    {
        return $this->registry->hook($sourceId, $point)
            ->tag(...$this->autoTags);
    }
}
