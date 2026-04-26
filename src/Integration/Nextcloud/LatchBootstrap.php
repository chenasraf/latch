<?php

declare(strict_types=1);

namespace Latch\Integration\Nextcloud;

use Latch\HookRegistry;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Server;

/**
 * Bootstrap helper for Nextcloud apps.
 *
 * Returns a BridgedRegistry that mirrors the core HookRegistry API but
 * bridges all hooks through NC's event dispatcher for cross-app communication.
 *
 * Source app:
 *
 *     public function boot(IBootContext $context): void
 *     {
 *         $registry = LatchBootstrap::registry();
 *
 *         $this->cms = $registry->registerSource('cms')
 *             ->action('page-published', PageEvent::class)
 *             ->collect('head-tags', PageContext::class);
 *     }
 *
 * Handler app:
 *
 *     public function boot(IBootContext $context): void
 *     {
 *         $registry = LatchBootstrap::registry();
 *         $handler = $registry->registerHandler('seo');
 *
 *         $handler->hook('cms', 'page-published')
 *             ->handle(fn ($payload) => SitemapQueue::push($payload->page->url));
 *     }
 */
final class LatchBootstrap
{
    private static ?BridgedRegistry $registry = null;

    /**
     * Get the bridged registry for this Nextcloud instance.
     */
    public static function registry(): BridgedRegistry
    {
        if (self::$registry === null) {
            $dispatcher = Server::get(IEventDispatcher::class);
            self::$registry = new BridgedRegistry($dispatcher);
        }

        return self::$registry;
    }

    /**
     * Reset all state. Intended for testing only.
     */
    public static function reset(): void
    {
        self::$registry = null;
        HookRegistry::resetInstance();
    }
}
