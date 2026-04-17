<?php

declare(strict_types=1);

namespace Latch\Exceptions;

final class SourceNotFoundException extends \RuntimeException
{
    public function __construct(string $sourceId)
    {
        parent::__construct("Hook source '{$sourceId}' not found.");
    }
}
