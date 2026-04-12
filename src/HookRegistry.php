<?php

declare(strict_types=1);

namespace Latch;

use Latch\Contracts\HookRegistryInterface;
use Latch\Exceptions\DuplicateHandlerException;
use Latch\Exceptions\DuplicateSourceException;
use Latch\Exceptions\SourceNotFoundException;

final class HookRegistry implements HookRegistryInterface
{
    /** @var array<string, SourceStore> */
    private array $sources = [];

    /** @var array<string, true> */
    private array $handlers = [];

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

        $this->handlers[$name] = true;

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
}
