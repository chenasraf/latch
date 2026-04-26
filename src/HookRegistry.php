<?php

declare(strict_types=1);

namespace Latch;

use Latch\Contracts\HookRegistryInterface;
use Latch\Exceptions\DuplicateHandlerException;
use Latch\Exceptions\DuplicateSourceException;
use Latch\Exceptions\SourceNotFoundException;

final class HookRegistry implements HookRegistryInterface
{
    private static ?self $instance = null;

    /** @var array<string, SourceStore> */
    private array $sources = [];

    /** @var array<string, HandlerInfo> */
    private array $handlers = [];

    /**
     * Get the shared singleton instance.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Reset the singleton instance. Intended for testing only.
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    public function registerSource(string $id, ?string $class = null): HookSource
    {
        if (isset($this->sources[$id])) {
            throw new DuplicateSourceException($id);
        }

        $source = new SourceStore($id, $class);
        $this->sources[$id] = $source;

        return new HookSource($source);
    }

    public function registerHandler(string $name, array $tags = []): HookHandler
    {
        if (isset($this->handlers[$name])) {
            throw new DuplicateHandlerException($name);
        }

        $this->handlers[$name] = new HandlerInfo($name, $tags);

        $resolver = function (string $sourceId): SourceStore {
            if (! isset($this->sources[$sourceId])) {
                throw new SourceNotFoundException($sourceId);
            }

            return $this->sources[$sourceId];
        };

        return new HookHandler($resolver, $name, $tags);
    }

    public function hasSource(string $id): bool
    {
        return isset($this->sources[$id]);
    }

    /**
     * @return array<string, SourceStore>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * List all registered handlers.
     *
     * @return array<string, HandlerInfo>
     */
    public function handlers(): array
    {
        return $this->handlers;
    }

    /**
     * Find handlers that have a specific capability tag.
     *
     * @return list<HandlerInfo>
     */
    public function handlersByTag(string $tag): array
    {
        return array_values(array_filter(
            $this->handlers,
            fn (HandlerInfo $h) => $h->hasTag($tag),
        ));
    }
}
