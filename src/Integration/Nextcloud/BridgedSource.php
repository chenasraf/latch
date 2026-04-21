<?php

declare(strict_types=1);

namespace Latch\Integration\Nextcloud;

use Latch\HookSource;
use Latch\HookType;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Wraps a HookSource to also dispatch through Nextcloud's event dispatcher.
 *
 * Mirrors the full HookSource API (declaration + invocation). Local handlers
 * are invoked directly through the underlying HookSource. Remote handlers
 * (in other apps) are reached through NC events.
 */
final class BridgedSource
{
    public readonly string $id;

    public function __construct(
        private readonly HookSource $source,
        private readonly IEventDispatcher $dispatcher,
    ) {
        $this->id = $source->id;
    }

    // --- Point declaration (delegates to inner source) ---

    /**
     * @param  class-string  $payloadClass
     * @return $this
     */
    public function filter(string $name, string $payloadClass): self
    {
        $this->source->filter($name, $payloadClass);

        return $this;
    }

    /**
     * @param  class-string  $payloadClass
     * @return $this
     */
    public function action(string $name, string $payloadClass): self
    {
        $this->source->action($name, $payloadClass);

        return $this;
    }

    /**
     * @param  class-string  $payloadClass
     * @return $this
     */
    public function collect(string $name, string $payloadClass): self
    {
        $this->source->collect($name, $payloadClass);

        return $this;
    }

    // --- Invocation (local + NC events) ---

    /**
     * Apply a filter chain - runs local handlers first, then NC event listeners.
     *
     * @param  list<string>  $tags
     */
    public function apply(string $point, object $payload, array $tags = []): object
    {
        $payload = $this->source->apply($point, $payload, $tags);

        $event = new LatchEvent($this->id, $point, HookType::Filter->value, $payload, $tags);
        $this->dispatcher->dispatch(LatchEvent::eventName($this->id, $point), $event);

        return $event->getPayload();
    }

    /**
     * Dispatch an action - runs local handlers and NC event listeners.
     *
     * @param  list<string>  $tags
     */
    public function dispatch(string $point, object $payload, array $tags = []): void
    {
        $this->source->dispatch($point, $payload, $tags);

        $event = new LatchEvent($this->id, $point, HookType::Action->value, $payload, $tags);
        $this->dispatcher->dispatch(LatchEvent::eventName($this->id, $point), $event);
    }

    /**
     * Collect from local handlers and NC event listeners.
     *
     * @param  list<string>  $tags
     * @return list<mixed>
     */
    public function collectFromHandlers(string $point, ?object $context = null, array $tags = []): array
    {
        $results = $this->source->collectFromHandlers($point, $context, $tags);

        $event = new LatchEvent(
            $this->id,
            $point,
            HookType::Collect->value,
            $context ?? new \stdClass,
            $tags,
        );
        $this->dispatcher->dispatch(LatchEvent::eventName($this->id, $point), $event);

        return [...$results, ...$event->getCollected()];
    }

    /**
     * Check local handlers. Note: cannot check remote handlers via NC events.
     */
    public function hasHandlers(string $point): bool
    {
        return $this->source->hasHandlers($point);
    }
}
