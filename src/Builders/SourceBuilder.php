<?php

declare(strict_types=1);

namespace Latch\Builders;

use Latch\HookPoint;
use Latch\HookType;
use Latch\SourceStore;

/**
 * Fluent builder for declaring hook points on a source.
 */
final class SourceBuilder
{
    public function __construct(
        private readonly SourceStore $source,
    ) {}

    /**
     * Declare a filter point.
     *
     * @param  class-string  $payloadClass
     */
    public function filter(string $name, string $payloadClass): self
    {
        $this->source->addPoint(new HookPoint($name, HookType::Filter, $payloadClass));

        return $this;
    }

    /**
     * Declare an action point.
     *
     * @param  class-string  $payloadClass
     */
    public function action(string $name, string $payloadClass): self
    {
        $this->source->addPoint(new HookPoint($name, HookType::Action, $payloadClass));

        return $this;
    }

    /**
     * Declare a collect point.
     *
     * @param  class-string  $payloadClass
     */
    public function collect(string $name, string $payloadClass): self
    {
        $this->source->addPoint(new HookPoint($name, HookType::Collect, $payloadClass));

        return $this;
    }

    public function getSource(): SourceStore
    {
        return $this->source;
    }
}
