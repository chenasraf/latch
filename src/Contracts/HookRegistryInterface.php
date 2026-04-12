<?php

declare(strict_types=1);

namespace Latch\Contracts;

use Latch\HookSource;
use Latch\RegisteredHandler;
use Latch\RegisteredSource;

interface HookRegistryInterface
{
    /**
     * Register a named source. Each name can only be registered once.
     *
     * @param  class-string|null  $class  Optional class associated with this source
     */
    public function registerSource(string $id, ?string $class = null): RegisteredSource;

    /**
     * Register a named handler. All hooks created through it are auto-tagged
     * with handler:{name} and any additional tags. Each name can only be registered once.
     *
     * @param  list<string>  $tags  Additional tags applied to all hooks from this handler
     */
    public function registerHandler(string $name, array $tags = []): RegisteredHandler;

    /**
     * Check whether a source is registered.
     */
    public function hasSource(string $id): bool;

    /**
     * List all registered sources.
     *
     * @return array<string, HookSource>
     */
    public function sources(): array;
}
