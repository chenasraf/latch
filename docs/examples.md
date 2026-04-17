# Examples

## End-to-End: CMS + SEO Plugin

A CMS app that declares extension points, and an SEO plugin that hooks into them.

### Source side — CMS app

```php
use Latch\HookRegistry;

class CmsApp
{
    public function __construct(private HookRegistry $registry)
    {
        $registry->source('cms')
            ->filter('render-html', RenderPayload::class)
            ->filter('page-title', TitlePayload::class)
            ->action('page-published', PageEvent::class)
            ->collect('head-tags', PageContext::class);
    }

    public function renderPage(Page $page): string
    {
        $payload = new RenderPayload($page->html);
        $payload = $this->registry->apply('cms', 'render-html', $payload);

        $title = new TitlePayload($page->title);
        $title = $this->registry->apply('cms', 'page-title', $title);

        $headTags = $this->registry->collect(
            'cms', 'head-tags', new PageContext($page)
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

        $this->registry->dispatch(
            'cms', 'page-published', new PageEvent($page, $actor)
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
        $registry->hook('cms', 'head-tags')
            ->priority(1)
            ->handle(fn (PageContext $ctx) => [
                "<meta name=\"description\" content=\"{$ctx->page->excerpt}\">",
                "<meta property=\"og:title\" content=\"{$ctx->page->title}\">",
            ]);

        $registry->hook('cms', 'page-title')
            ->priority(50)
            ->handle(fn (TitlePayload $t) => $t->withValue(
                $t->value . ' | My Site'
            ));

        $registry->hook('cms', 'render-html')
            ->priority(90)
            ->handle(fn (RenderPayload $p) => $p->withHtml(
                $p->html . $this->jsonLdScript($p)
            ));

        $registry->hook('cms', 'page-published')
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
invoke specific ones. Pass a list of tags to `apply()`, `dispatch()`, or `collect()` and only
handlers with at least one matching tag will run.

```php
// An SEO plugin and an analytics plugin both hook into head-tags
$registry->hook('cms', 'head-tags')
    ->tag('seo')
    ->handle(fn (PageContext $ctx) => [
        "<meta name=\"description\" content=\"{$ctx->page->excerpt}\">",
    ]);

$registry->hook('cms', 'head-tags')
    ->tag('analytics')
    ->handle(fn (PageContext $ctx) => [
        '<script src="/analytics.js"></script>',
    ]);

// Collect from all handlers (default)
$allTags = $registry->collect('cms', 'head-tags', $context);

// Collect only from SEO handlers
$seoTags = $registry->collect('cms', 'head-tags', $context, ['seo']);
```

### Discovery-based approach

Instead of relying on tags alone, the source can first discover which handlers are available, show
the user a picker, and then target the chosen one.

```php
// Step 1: Discover available notification providers
$registry->source('cms')
    ->collect('notification-providers', stdClass::class)
    ->action('notify-subscribers', NotifyPayload::class);

$providers = $registry->collect('cms', 'notification-providers');
// Returns: [['id' => 'email', 'label' => 'Email'], ['id' => 'push', 'label' => 'Push Notifications']]

// Step 2: Show a picker in the UI and get the user's choice
$chosen = $userChoice; // e.g. 'email'

// Step 3: Dispatch to the chosen provider using tag filtering
$registry->dispatch(
    'cms', 'notify-subscribers', $payload, [$chosen]
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
        $registry->source('cms')
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
        $registry->hook('cms', 'render-html')
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

        $registry->source('myapp')
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

        if ($registry->hasSource('myapp')) {
            $registry->hook('myapp', 'dashboard-widgets')
                ->handle(fn (DashboardContext $ctx) => [
                    new Widget('Activity', renderActivityFeed()),
                ]);
        }
    }
}
```
