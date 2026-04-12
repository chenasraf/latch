<?php

declare(strict_types=1);

namespace Latch\Integration\Laravel;

use Illuminate\Support\ServiceProvider;
use Latch\Contracts\HookRegistryInterface;
use Latch\HookRegistry;

class LatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HookRegistryInterface::class, HookRegistry::class);
        $this->app->alias(HookRegistryInterface::class, HookRegistry::class);
        $this->app->alias(HookRegistryInterface::class, 'latch');
    }
}
