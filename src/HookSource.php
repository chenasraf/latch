<?php

declare(strict_types=1);

namespace Latch;

/**
 * Holds a source's declared hook points.
 */
final class HookSource
{
    /** @var array<string, HookPoint<object>> */
    private array $points = [];

    /** @var array<string, list<HookHandler>> */
    private array $handlers = [];

    public function __construct(
        public readonly string $id,
        public readonly ?string $class = null,
    ) {}

    /**
     * @param  HookPoint<object>  $point
     */
    public function addPoint(HookPoint $point): void
    {
        $this->points[$point->name] = $point;
    }

    public function hasPoint(string $name): bool
    {
        return isset($this->points[$name]);
    }

    /**
     * @return HookPoint<object>
     */
    public function getPoint(string $name): HookPoint
    {
        if (! isset($this->points[$name])) {
            throw new Exceptions\HookPointNotFoundException($this->id, $name);
        }

        return $this->points[$name];
    }

    /**
     * @return array<string, HookPoint<object>>
     */
    public function points(): array
    {
        return $this->points;
    }

    public function addHandler(string $point, HookHandler $handler): void
    {
        if (! $this->hasPoint($point)) {
            throw new Exceptions\HookPointNotFoundException($this->id, $point);
        }

        $this->handlers[$point][] = $handler;
    }

    /**
     * Get handlers for a point, sorted by priority (lower first).
     *
     * @return list<HookHandler>
     */
    public function getHandlers(string $point): array
    {
        $handlers = $this->handlers[$point] ?? [];

        usort($handlers, fn (HookHandler $a, HookHandler $b) => $a->priority <=> $b->priority);

        return $handlers;
    }
}
