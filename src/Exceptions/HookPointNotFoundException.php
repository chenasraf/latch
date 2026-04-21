<?php

declare(strict_types=1);

namespace Latch\Exceptions;

final class HookPointNotFoundException extends \RuntimeException
{
    public function __construct(string $sourceId, string $point)
    {
        parent::__construct("Hook point '{$point}' not found in source '{$sourceId}'.");
    }
}
