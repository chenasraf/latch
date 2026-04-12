<?php

declare(strict_types=1);

namespace Latch\Contracts;

use Latch\Builders\HandlerBuilder;
use Latch\Builders\SourceBuilder;
use Latch\HookSource;

interface HookRegistryInterface
{
    /**
     * Register a hook source.
     *
     * @param  class-string|null  $class  Optional class associated with this source
     */
    public function source(string $id, ?string $class = null): SourceBuilder;

    /**
     * Attach a handler to a hook point.
     */
    public function hook(string $sourceId, string $point): HandlerBuilder;

    /**
     * Apply a filter chain — each handler receives and returns a modified payload.
     */
    public function apply(string $sourceId, string $point, object $payload): object;

    /**
     * Dispatch an action — fire-and-forget, return value is ignored.
     */
    public function dispatch(string $sourceId, string $point, object $payload): void;

    /**
     * Collect contributions — each handler returns an array, results are merged flat.
     *
     * @return list<mixed>
     */
    public function collect(string $sourceId, string $point, ?object $context = null): array;

    /**
     * Check whether a source is registered.
     */
    public function hasSource(string $id): bool;

    /**
     * Check whether a source has handlers registered for a given point.
     */
    public function hasHandlers(string $sourceId, string $point): bool;

    /**
     * List all registered sources.
     *
     * @return array<string, HookSource>
     */
    public function sources(): array;
}
