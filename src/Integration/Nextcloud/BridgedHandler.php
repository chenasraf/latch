<?php

declare(strict_types=1);

namespace Latch\Integration\Nextcloud;

use Latch\Builders\HandlerBuilder;
use Latch\Contracts\HookRegistryInterface;
use Latch\Exceptions\ReservedTagException;
use Latch\HookHandler;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Wraps a HookHandler to support hooking into both local and remote sources.
 *
 * When the target source exists in the local registry, delegates to the inner
 * HookHandler (normal behavior). When the source is in another app, returns a
 * BridgedHandlerBuilder that registers an NC event listener instead.
 */
final class BridgedHandler
{
    public readonly string $name;

    /** @var list<string> */
    private array $extraTags = [];

    public function __construct(
        private readonly HookHandler $handler,
        private readonly IEventDispatcher $dispatcher,
        private readonly HookRegistryInterface $registry,
    ) {
        $this->name = $handler->name;
    }

    /**
     * Add additional tags to all future hooks from this handler.
     *
     * @return $this
     */
    public function globalTags(string ...$tags): self
    {
        $tagValues = array_values($tags);

        foreach ($tagValues as $tag) {
            if (str_starts_with($tag, 'handler:')) {
                throw new ReservedTagException($tag);
            }
        }

        $this->handler->globalTags(...$tags);
        $this->extraTags = [...$this->extraTags, ...$tagValues];

        return $this;
    }

    /**
     * Hook into a source's extension point.
     *
     * If the source exists locally, returns a normal HandlerBuilder.
     * If the source is remote (in another app), returns a BridgedHandlerBuilder
     * that registers via NC events.
     */
    public function hook(string $sourceId, string $point): HandlerBuilder|BridgedHandlerBuilder
    {
        if ($this->registry->hasSource($sourceId)) {
            return $this->handler->hook($sourceId, $point);
        }

        $allTags = ["handler:{$this->name}", ...$this->extraTags];

        return (new BridgedHandlerBuilder($this->dispatcher, $sourceId, $point))
            ->autoTag(...$allTags);
    }
}
