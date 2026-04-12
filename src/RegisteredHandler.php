<?php

declare(strict_types=1);

namespace Latch;

use Latch\Builders\HandlerBuilder;
use Latch\Exceptions\ReservedTagException;

/**
 * A named handler scope that auto-tags all hooks with handler:{name} and any additional tags.
 */
final class RegisteredHandler
{
    /** @var list<string> */
    private array $autoTags;

    /**
     * @param  \Closure(string): HookSource  $sourceResolver
     * @param  list<string>  $tags  Additional tags applied to all hooks from this handler
     */
    public function __construct(
        private readonly \Closure $sourceResolver,
        public readonly string $name,
        array $tags = [],
    ) {
        self::validateTags($tags);
        $this->autoTags = ["handler:{$this->name}", ...$tags];
    }

    /**
     * Add additional tags to all future hooks from this handler.
     */
    public function globalTags(string ...$tags): self
    {
        $tags = array_values($tags);
        self::validateTags($tags);
        $this->autoTags = [...$this->autoTags, ...$tags];

        return $this;
    }

    public function hook(string $sourceId, string $point): HandlerBuilder
    {
        $source = ($this->sourceResolver)($sourceId);
        $source->getPoint($point);

        return (new HandlerBuilder($source, $point))
            ->autoTag(...$this->autoTags);
    }

    /**
     * @param  list<string>  $tags
     */
    private static function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (str_starts_with($tag, 'handler:')) {
                throw new ReservedTagException($tag);
            }
        }
    }
}
