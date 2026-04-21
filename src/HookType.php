<?php

declare(strict_types=1);

namespace Latch;

enum HookType: string
{
    case Filter = 'filter';
    case Action = 'action';
    case Collect = 'collect';
}
