<?php

declare(strict_types=1);

namespace Latch;

use Latch\Contracts\HookRegistryInterface;
use Latch\Exceptions\DuplicateHandlerException;
use Latch\Exceptions\DuplicateSourceException;
use Latch\Exceptions\SourceNotFoundException;

final class HookRegistry implements HookRegistryInterface
{
    /** @var array<string, HookSource> */
    private array $sources = [];

    /** @var array<string, true> */
    private array $handlers = [];

    public function registerSource(string $id, ?string $class = null): RegisteredSource
    {
        if (isset($this->sources[$id])) {
            throw new DuplicateSourceException($id);
        }

        $source = new HookSource($id, $class);
        $this->sources[$id] = $source;

        return new RegisteredSource($source);
    }

    public function registerHandler(string $name, array $tags = []): RegisteredHandler
    {
        if (isset($this->handlers[$name])) {
            throw new DuplicateHandlerException($name);
        }

        $this->handlers[$name] = true;

        $resolver = function (string $sourceId): HookSource {
            if (! isset($this->sources[$sourceId])) {
                throw new SourceNotFoundException($sourceId);
            }

            return $this->sources[$sourceId];
        };

        return new RegisteredHandler($resolver, $name, $tags);
    }

    public function hasSource(string $id): bool
    {
        return isset($this->sources[$id]);
    }

    /**
     * @return array<string, HookSource>
     */
    public function sources(): array
    {
        return $this->sources;
    }
}
