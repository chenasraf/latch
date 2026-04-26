<?php

declare(strict_types=1);

namespace Latch\Exceptions;

use Latch\HookType;

final class HookTypeMismatchException extends \RuntimeException
{
    public function __construct(string $sourceId, string $point, HookType $expected, HookType $actual)
    {
        parent::__construct(
            "Hook point '{$point}' in source '{$sourceId}' is of type '{$expected->value}', but was invoked as '{$actual->value}'."
        );
    }
}
