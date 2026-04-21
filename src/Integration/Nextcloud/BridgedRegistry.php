<?php

declare(strict_types=1);

namespace Latch\Integration\Nextcloud;

use Latch\HookRegistry;
use Latch\SourceStore;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * Drop-in replacement for HookRegistry that bridges all hooks through NC's event dispatcher.
 *
 * Returns BridgedSource and BridgedHandler instances that mirror the core API
 * but also dispatch/listen through NC events for cross-app communication.
 */
final class BridgedRegistry
{
    private readonly HookRegistry $registry;

    public function __construct(
        private readonly IEventDispatcher $dispatcher,
    ) {
        $this->registry = HookRegistry::getInstance();
    }

    /**
     * Register a named source. Returns a BridgedSource that dispatches through
     * both local handlers and NC events.
     *
     * @param  class-string|null  $class
     */
    public function registerSource(string $id, ?string $class = null): BridgedSource
    {
        $source = $this->registry->registerSource($id, $class);

        return new BridgedSource($source, $this->dispatcher);
    }

    /**
     * Register a named handler. Returns a BridgedHandler that can hook into
     * both local sources and remote sources (in other apps) via NC events.
     *
     * @param  list<string>  $tags
     */
    public function registerHandler(string $name, array $tags = []): BridgedHandler
    {
        $handler = $this->registry->registerHandler($name, $tags);

        return new BridgedHandler($handler, $this->dispatcher, $this->registry);
    }

    public function hasSource(string $id): bool
    {
        return $this->registry->hasSource($id);
    }

    /**
     * @return array<string, SourceStore>
     */
    public function sources(): array
    {
        return $this->registry->sources();
    }
}
