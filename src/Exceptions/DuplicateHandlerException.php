<?php

declare(strict_types=1);

namespace Latch\Exceptions;

final class DuplicateHandlerException extends \RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct(
            "Handler '{$name}' is already registered. Reuse the existing HookHandler instance instead."
        );
    }
}
