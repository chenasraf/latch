<?php

declare(strict_types=1);

namespace Latch\Integration\Nextcloud;

use Latch\Contracts\HookRegistryInterface;
use Latch\HookRegistry;

/**
 * Bootstrap helper for Nextcloud apps.
 *
 * Important: For cross-app communication to work, Latch must be installed as a
 * Nextcloud app (not bundled in each app's vendor/). This ensures all apps share
 * the same class autoloader and singleton instance.
 *
 * Usage from your Application class:
 *
 *     public function boot(IBootContext $context): void
 *     {
 *         $registry = LatchBootstrap::registry();
 *
 *         $registry->registerSource('my-nc-app')
 *             ->filter('content-render', RenderPayload::class);
 *     }
 */
final class LatchBootstrap
{
    /**
     * Get the shared HookRegistry singleton.
     */
    public static function registry(): HookRegistryInterface
    {
        return HookRegistry::getInstance();
    }
}
