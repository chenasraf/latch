<?php

declare(strict_types=1);

namespace Latch\Exceptions;

final class DuplicateSourceException extends \RuntimeException
{
    public function __construct(string $id)
    {
        parent::__construct(
            "Source '{$id}' is already registered. Reuse the existing HookSource instance instead."
        );
    }
}
