<?php

declare(strict_types=1);

namespace Latch;

/**
 * Represents a declared extension point within a hook source.
 *
 * @template T of object
 */
final class HookPoint
{
    /**
     * @param  string  $name  The point name
     * @param  HookType  $type  The hook type (filter, action, collect)
     * @param  class-string<T>  $payloadClass  The expected payload/context class
     */
    public function __construct(
        public readonly string $name,
        public readonly HookType $type,
        public readonly string $payloadClass,
    ) {}
}
