<?php

declare(strict_types=1);

namespace Latch\Integration\Nextcloud;

use Latch\Exceptions\ReservedTagException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Mirrors HandlerBuilder for remote sources.
 *
 * Instead of registering a HandlerEntry on a local SourceStore, this builder
 * registers an NC event listener that handles hooks dispatched from another app.
 */
final class BridgedHandlerBuilder
{
    private int $priority = 10;

    private bool $exclusive = false;

    private ?\Closure $condition = null;

    /** @var list<string> */
    private array $tags = [];

    public function __construct(
        private readonly IEventDispatcher $dispatcher,
        private readonly string $sourceId,
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
        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'handler:')) {
                throw new ReservedTagException($tag);
            }
        }

        $this->tags = [...$this->tags, ...array_values($tags)];

        return $this;
    }

    /**
     * @internal Used by BridgedHandler to set auto-tags including handler: prefix.
     */
    public function autoTag(string ...$tags): self
    {
        $this->tags = [...$this->tags, ...array_values($tags)];

        return $this;
    }

    /**
     * Register the handler via NC event listener. This finalizes the builder.
     */
    public function handle(callable $handler): void
    {
        $callback = $handler(...);
        $condition = $this->condition;
        $handlerTags = $this->tags;
        $exclusive = $this->exclusive;

        $this->dispatcher->addListener(
            LatchEvent::eventName($this->sourceId, $this->point),
            function (Event $event) use ($callback, $condition, $handlerTags, $exclusive): void {
                if (! method_exists($event, 'getHookType')) {
                    return;
                }

                // Tag filtering: skip if the source requested specific tags and we don't match
                if (method_exists($event, 'getTags')) {
                    $requestedTags = $event->getTags();
                    if ($requestedTags !== [] && array_intersect($handlerTags, $requestedTags) === []) {
                        return;
                    }
                }

                $payload = $event->getPayload();

                // Condition check
                if ($condition !== null && ! $condition($payload)) {
                    return;
                }

                $hookType = $event->getHookType();

                switch ($hookType) {
                    case 'filter':
                        $result = $callback($payload);
                        if ($result !== null) {
                            $event->setPayload($result);
                        }
                        break;

                    case 'action':
                        $callback($payload);
                        break;

                    case 'collect':
                        $items = $callback($payload);
                        if (is_array($items)) {
                            $event->addCollected($items);
                        }
                        break;
                }

                // Exclusive: stop other NC listeners from running
                if ($exclusive && method_exists($event, 'stopPropagation')) {
                    $event->stopPropagation();
                }
            },
            $this->priority,
        );
    }
}
