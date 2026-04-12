<?php

declare(strict_types=1);

namespace Latch;

use Latch\Builders\HandlerBuilder;
use Latch\Builders\SourceBuilder;
use Latch\Contracts\HookRegistryInterface;
use Latch\Exceptions\HookTypeMismatchException;
use Latch\Exceptions\SourceNotFoundException;

final class HookRegistry implements HookRegistryInterface
{
    /** @var array<string, HookSource> */
    private array $sources = [];

    public function source(string $id, ?string $class = null): SourceBuilder
    {
        $source = new HookSource($id, $class);
        $this->sources[$id] = $source;

        return new SourceBuilder($source);
    }

    public function hook(string $sourceId, string $point): HandlerBuilder
    {
        $source = $this->resolveSource($sourceId);

        // Validate that the point exists (getPoint throws if not)
        $source->getPoint($point);

        return new HandlerBuilder($source, $point);
    }

    public function apply(string $sourceId, string $point, object $payload): object
    {
        $source = $this->resolveSource($sourceId);
        $hookPoint = $source->getPoint($point);

        if ($hookPoint->type !== HookType::Filter) {
            throw new HookTypeMismatchException($sourceId, $point, $hookPoint->type, HookType::Filter);
        }

        $handlers = $source->getHandlers($point);

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

    public function dispatch(string $sourceId, string $point, object $payload): void
    {
        $source = $this->resolveSource($sourceId);
        $hookPoint = $source->getPoint($point);

        if ($hookPoint->type !== HookType::Action) {
            throw new HookTypeMismatchException($sourceId, $point, $hookPoint->type, HookType::Action);
        }

        $handlers = $source->getHandlers($point);

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

    public function collect(string $sourceId, string $point, ?object $context = null): array
    {
        $source = $this->resolveSource($sourceId);
        $hookPoint = $source->getPoint($point);

        if ($hookPoint->type !== HookType::Collect) {
            throw new HookTypeMismatchException($sourceId, $point, $hookPoint->type, HookType::Collect);
        }

        $handlers = $source->getHandlers($point);
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

    public function hasSource(string $id): bool
    {
        return isset($this->sources[$id]);
    }

    public function hasHandlers(string $sourceId, string $point): bool
    {
        $source = $this->resolveSource($sourceId);
        $source->getPoint($point);

        return $source->getHandlers($point) !== [];
    }

    /**
     * @return array<string, HookSource>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    private function resolveSource(string $sourceId): HookSource
    {
        if (! isset($this->sources[$sourceId])) {
            throw new SourceNotFoundException($sourceId);
        }

        return $this->sources[$sourceId];
    }
}
