<?php

declare(strict_types=1);

namespace Latch\Exceptions;

final class ReservedTagException extends \RuntimeException
{
    public function __construct(string $tag)
    {
        parent::__construct(
            "Tag '{$tag}' uses the reserved 'handler:' prefix. Use registerHandler() to set handler identity."
        );
    }
}
