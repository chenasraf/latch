<?php

declare(strict_types=1);

namespace Latch;

use Latch\Builders\SourceBuilder;
use Latch\Exceptions\HookTypeMismatchException;

/**
 * A registered source that owns extension points and invokes hooks.
 */
final class RegisteredSource
{
    private readonly SourceBuilder $builder;

    public readonly string $id;

    public function __construct(
        private readonly HookSource $source,
    ) {
        $this->id = $source->id;
        $this->builder = new SourceBuilder($source);
    }

    // --- Point declaration ---

    /**
     * Declare a filter point.
     *
     * @param  class-string  $payloadClass
     */
    public function filter(string $name, string $payloadClass): self
    {
        $this->builder->filter($name, $payloadClass);

        return $this;
    }

    /**
     * Declare an action point.
     *
     * @param  class-string  $payloadClass
     */
    public function action(string $name, string $payloadClass): self
    {
        $this->builder->action($name, $payloadClass);

        return $this;
    }

    /**
     * Declare a collect point.
     *
     * @param  class-string  $payloadClass
     */
    public function collect(string $name, string $payloadClass): self
    {
        $this->builder->collect($name, $payloadClass);

        return $this;
    }

    // --- Invocation ---

    /**
     * Apply a filter chain on this source's hook point.
     *
     * @param  list<string>  $tags  When non-empty, only handlers with at least one matching tag are invoked
     */
    public function apply(string $point, object $payload, array $tags = []): object
    {
        $hookPoint = $this->source->getPoint($point);

        if ($hookPoint->type !== HookType::Filter) {
            throw new HookTypeMismatchException($this->id, $point, $hookPoint->type, HookType::Filter);
        }

        $handlers = $this->source->getHandlers($point, $tags);

        foreach ($handlers as $handler) {
            if (! $handler->shouldRun($payload)) {
                continue;
            }

            $payload = ($handler->handler)($payload);

            if ($handler->exclusive) {
                break;
            }
        }

        return $payload;
    }

    /**
     * Dispatch an action on this source's hook point.
     *
     * @param  list<string>  $tags  When non-empty, only handlers with at least one matching tag are invoked
     */
    public function dispatch(string $point, object $payload, array $tags = []): void
    {
        $hookPoint = $this->source->getPoint($point);

        if ($hookPoint->type !== HookType::Action) {
            throw new HookTypeMismatchException($this->id, $point, $hookPoint->type, HookType::Action);
        }

        $handlers = $this->source->getHandlers($point, $tags);

        foreach ($handlers as $handler) {
            if (! $handler->shouldRun($payload)) {
                continue;
            }

            ($handler->handler)($payload);

            if ($handler->exclusive) {
                break;
            }
        }
    }

    /**
     * Collect contributions from this source's hook point.
     *
     * @param  list<string>  $tags  When non-empty, only handlers with at least one matching tag are invoked
     * @return list<mixed>
     */
    public function collectFromHandlers(string $point, ?object $context = null, array $tags = []): array
    {
        $hookPoint = $this->source->getPoint($point);

        if ($hookPoint->type !== HookType::Collect) {
            throw new HookTypeMismatchException($this->id, $point, $hookPoint->type, HookType::Collect);
        }

        $handlers = $this->source->getHandlers($point, $tags);
        $results = [];

        foreach ($handlers as $handler) {
            if (! $handler->shouldRun($context)) {
                continue;
            }

            $items = ($handler->handler)($context);
            $results = [...$results, ...$items];

            if ($handler->exclusive) {
                break;
            }
        }

        return $results;
    }

    /**
     * Check whether this source has handlers registered for a given point.
     */
    public function hasHandlers(string $point): bool
    {
        $this->source->getPoint($point);

        return $this->source->getHandlers($point) !== [];
    }
}
