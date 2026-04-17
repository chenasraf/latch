# Examples

## End-to-End: CMS + SEO Plugin

A CMS app that declares extension points, and an SEO plugin that hooks into them.

### Source side — CMS app

```php
use Latch\HookRegistry;

class CmsApp
{
    private HookSource $cms;

    public function __construct(private HookRegistry $registry)
    {
        $this->cms = $registry->registerSource('cms')
            ->filter('render-html', RenderPayload::class)
            ->filter('page-title', TitlePayload::class)
            ->action('page-published', PageEvent::class)
            ->collect('head-tags', PageContext::class);
    }

    public function renderPage(Page $page): string
    {
        $payload = new RenderPayload($page->html);
        $payload = $this->cms->apply('render-html', $payload);

        $title = new TitlePayload($page->title);
        $title = $this->cms->apply('page-title', $title);

        $headTags = $this->cms->collectFromHandlers(
            'head-tags', new PageContext($page)
        );

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
            <title>{$title->value}</title>
            {$this->renderTags($headTags)}
        </head>
        <body>{$payload->html}</body>
        </html>
        HTML;
    }

    public function publishPage(Page $page, User $actor): void
    {
        $page->publish();

        $this->cms->dispatch(
            'page-published', new PageEvent($page, $actor)
        );
    }

    private function renderTags(array $tags): string
    {
        return implode("\n    ", $tags);
    }
}
```

### Handler side — SEO plugin

```php
use Latch\HookRegistry;

class SeoPlugin
{
    public function __construct(HookRegistry $registry)
    {
        // Register a named handler - all hooks auto-tagged with 'handler:seo'
        $seo = $registry->registerHandler('seo');

        $seo->hook('cms', 'head-tags')
            ->priority(1)
            ->handle(fn (PageContext $ctx) => [
                "<meta name=\"description\" content=\"{$ctx->page->excerpt}\">",
                "<meta property=\"og:title\" content=\"{$ctx->page->title}\">",
            ]);

        $seo->hook('cms', 'page-title')
            ->priority(50)
            ->handle(fn (TitlePayload $t) => $t->withValue(
                $t->value . ' | My Site'
            ));

        $seo->hook('cms', 'render-html')
            ->priority(90)
            ->handle(fn (RenderPayload $p) => $p->withHtml(
                $p->html . $this->jsonLdScript($p)
            ));

        $seo->hook('cms', 'page-published')
            ->handle(fn (PageEvent $e) => SitemapQueue::push($e->page->url));
    }

    private function jsonLdScript(RenderPayload $payload): string
    {
        return '<script type="application/ld+json">{"@type":"WebPage"}</script>';
    }
}
```

### Wiring

```php
$registry = new HookRegistry();

$cms = new CmsApp($registry);
$seo = new SeoPlugin($registry);

echo $cms->renderPage($page);
```

## Tag Filtering: Targeted Invocation

When multiple handlers are registered for the same point, the source can use tag filtering to only
invoke specific ones. Pass a list of tags to `apply()`, `dispatch()`, or `collectFromHandlers()` and only
handlers with at least one matching tag will run.

Using `registerHandler()`, each plugin gets auto-tagged with `handler:{name}`, making targeted
invocation straightforward.

```php
// Source registers with head-tags collect point
$cms = $registry->registerSource('cms')
    ->collect('head-tags', PageContext::class);

// SEO plugin registers with a named handler
$seo = $registry->registerHandler('seo');
$seo->hook('cms', 'head-tags')
    ->handle(fn (PageContext $ctx) => [
        "<meta name=\"description\" content=\"{$ctx->page->excerpt}\">",
    ]);

// Analytics plugin registers with its own named handler
$analytics = $registry->registerHandler('analytics');
$analytics->hook('cms', 'head-tags')
    ->handle(fn (PageContext $ctx) => [
        '<script src="/analytics.js"></script>',
    ]);

// Collect from all handlers (default)
$allTags = $cms->collectFromHandlers('head-tags', $context);

// Collect only from the SEO handler
$seoTags = $cms->collectFromHandlers('head-tags', $context, ['handler:seo']);
```

### Discovery-based approach

Instead of relying on tags alone, the source can first discover which handlers are available, show
the user a picker, and then target the chosen one.

```php
// Step 1: Discover available notification providers
$cms = $registry->registerSource('cms')
    ->collect('notification-providers', stdClass::class)
    ->action('notify-subscribers', NotifyPayload::class);

// Each plugin registers as a provider
$email = $registry->registerHandler('email');
$email->hook('cms', 'notification-providers')
    ->handle(fn () => [['id' => 'email', 'label' => 'Email']]);
$email->hook('cms', 'notify-subscribers')
    ->handle(fn (NotifyPayload $p) => EmailService::send($p));

$push = $registry->registerHandler('push');
$push->hook('cms', 'notification-providers')
    ->handle(fn () => [['id' => 'push', 'label' => 'Push Notifications']]);
$push->hook('cms', 'notify-subscribers')
    ->handle(fn (NotifyPayload $p) => PushService::send($p));

// Step 2: Show a picker in the UI and get the user's choice
$providers = $cms->collectFromHandlers('notification-providers');
$chosen = $userChoice; // e.g. 'email'

// Step 3: Dispatch to the chosen provider using its auto-tag
$cms->dispatch(
    'notify-subscribers', $payload, ["handler:{$chosen}"]
);
```

## Laravel Integration

The service provider is auto-discovered. Inject `HookRegistryInterface` anywhere Laravel resolves
dependencies.

### Source — CMS service provider

```php
use Latch\Contracts\HookRegistryInterface;

class CmsServiceProvider extends ServiceProvider
{
    public function boot(HookRegistryInterface $registry): void
    {
        $cms = $registry->registerSource('cms')
            ->filter('render-html', RenderPayload::class)
            ->action('page-published', PageEvent::class)
            ->collect('nav-items', NavContext::class);
    }
}
```

### Handler — SEO service provider

```php
class SeoServiceProvider extends ServiceProvider
{
    public function boot(HookRegistryInterface $registry): void
    {
        $seo = $registry->registerHandler('seo');

        $seo->hook('cms', 'render-html')
            ->priority(90)
            ->handle(fn (RenderPayload $p) => $p->withHtml(
                $p->html . '<meta name="generator" content="seo-plugin">'
            ));
    }
}
```

You can also resolve via the `latch` alias:

```php
$registry = app('latch');
```

## Nextcloud Integration

Use the `LatchBootstrap` helper in your `Application` class.

### Source — app that owns the hooks

```php
namespace OCA\MyApp\AppInfo;

use Latch\Integration\Nextcloud\LatchBootstrap;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap
{
    public function __construct()
    {
        parent::__construct('myapp');
    }

    public function register(IRegistrationContext $context): void
    {
        LatchBootstrap::register($context);
    }

    public function boot(IBootContext $context): void
    {
        $registry = LatchBootstrap::registry($context);

        $myapp = $registry->registerSource('myapp')
            ->filter('content-render', RenderPayload::class)
            ->collect('dashboard-widgets', DashboardContext::class);
    }
}
```

### Handler — another Nextcloud app

```php
class Application extends App implements IBootstrap
{
    public function register(IRegistrationContext $context): void {}

    public function boot(IBootContext $context): void
    {
        $registry = LatchBootstrap::registry($context);

        $activity = $registry->registerHandler('activity');

        if ($registry->hasSource('myapp')) {
            $activity->hook('myapp', 'dashboard-widgets')
                ->handle(fn (DashboardContext $ctx) => [
                    new Widget('Activity', renderActivityFeed()),
                ]);
        }
    }
}
```
