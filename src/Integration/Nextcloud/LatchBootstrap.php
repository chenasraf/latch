<?php

declare(strict_types=1);

namespace Latch\Integration\Nextcloud;

use Latch\Contracts\HookRegistryInterface;
use Latch\HookRegistry;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Bootstrap helper for Nextcloud apps.
 *
 * Call the static methods from your Application class that implements IBootstrap:
 *
 *     public function register(IRegistrationContext $context): void
 *     {
 *         LatchBootstrap::register($context);
 *     }
 *
 *     public function boot(IBootContext $context): void
 *     {
 *         $registry = LatchBootstrap::registry($context);
 *
 *         $registry->source('my-nc-app')
 *             ->filter('content-render', RenderPayload::class);
 *     }
 */
final class LatchBootstrap
{
    /**
     * Register the HookRegistry as a shared service in the Nextcloud DI container.
     */
    public static function register(IRegistrationContext $context): void
    {
        $context->registerService(HookRegistryInterface::class, fn () => new HookRegistry, true);
        $context->registerServiceAlias(HookRegistry::class, HookRegistryInterface::class);
    }

    /**
     * Retrieve the HookRegistry from the app container during boot.
     */
    public static function registry(IBootContext $context): HookRegistryInterface
    {
        return $context->getAppContainer()->get(HookRegistryInterface::class);
    }
}
