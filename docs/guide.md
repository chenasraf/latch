# Guide

## Concepts

Latch has two sides:

- **Source side** — the app that owns the extension points. It declares what hooks exist, what
  payload types they expect, and invokes them at the right moment.
- **Handler side** — any package that wants to extend the source. It attaches callables to the
  declared points.

Sources must be registered before handlers can attach. Hooking into an undeclared source or point
throws immediately — there are no silent no-ops.

## Source Side

### Registering a source

A source declares its identity and extension points upfront. Each point has a name, a type, and the
class of payload/context it works with.

```php
use Latch\HookRegistry;

$registry = new HookRegistry();

$cms = $registry->registerSource('cms')
    ->filter('render-html', RenderPayload::class)
    ->filter('page-title', TitlePayload::class)
    ->action('page-published', PageEvent::class)
    ->action('page-deleted', PageEvent::class)
    ->collect('sidebar-widgets', SidebarContext::class)
    ->collect('admin-menu', MenuContext::class);
```

You can optionally pass a class name to associate with the source for introspection:

```php
$registry->registerSource('cms', CmsApplication::class);
```

### Invoking filters

Filters run a chain of handlers, each receiving the payload and returning a modified version. Use
immutable value objects with `with*` methods for clean chaining.

```php
class RenderPayload
{
    public function __construct(
        public readonly string $html,
        public readonly array $meta = [],
    ) {}

    public function withHtml(string $html): self
    {
        return new self($html, $this->meta);
    }

    public function withMeta(string $key, mixed $value): self
    {
        return new self($this->html, [...$this->meta, $key => $value]);
    }
}

$payload = new RenderPayload($rawHtml);
$payload = $cms->apply('render-html', $payload);
echo $payload->html;
```

If no handlers are registered, the payload is returned unchanged.

### Invoking actions

Actions are fire-and-forget. The source broadcasts an event; handler return values are ignored.

```php
class PageEvent
{
    public function __construct(
        public readonly Page $page,
        public readonly User $actor,
    ) {}
}

$cms->dispatch('page-published', new PageEvent($page, $currentUser));
```

### Invoking collectors

Collectors gather contributions from all handlers. Each handler returns an array; results are
merged into a single flat list.

```php
class SidebarContext
{
    public function __construct(
        public readonly Page $page,
        public readonly User $viewer,
    ) {}
}

class Widget
{
    public function __construct(
        public readonly string $title,
        public readonly string $html,
    ) {}
}

$widgets = $cms->collectFromHandlers('sidebar-widgets', new SidebarContext($page, $viewer));

foreach ($widgets as $widget) {
    echo "<div class=\"widget\"><h3>{$widget->title}</h3>{$widget->html}</div>";
}
```

The context argument is optional — pass `null` or omit it if handlers don't need context:

```php
$items = $cms->collectFromHandlers('admin-menu');
```

If no handlers are registered, an empty array is returned.

## Handler Side

### Registering a handler

Every handler must be registered with a name using `registerHandler()`. This creates a
`HookHandler` that you use to attach hooks. All hooks are automatically tagged with
`handler:{name}`.

```php
$seo = $registry->registerHandler('seo');

$seo->hook('cms', 'render-html')
    ->handle(fn (RenderPayload $p) => $p->withHtml(
        str_replace('{{year}}', date('Y'), $p->html)
    ));
```

Each name can only be registered once. Attempting to register the same name again throws
`DuplicateHandlerException`. Pass the `HookHandler` instance around via DI or store it as
a property to reuse across your package.

### Additional tags

You can pass additional tags at registration time:

```php
$seo = $registry->registerHandler('seo', ['premium']);
// All hooks get both 'handler:seo' and 'premium'
```

Or add more tags later with `globalTags()` - these apply to all hooks created after the call:

```php
$seo = $registry->registerHandler('seo');

$seo->hook('cms', 'head-tags')->handle(...);
// Tagged: ['handler:seo']

$seo->globalTags('v2');

$seo->hook('cms', 'render-html')->handle(...);
// Tagged: ['handler:seo', 'v2']
```

Per-hook tags still work and are merged with the auto-tags:

```php
$seo->hook('cms', 'page-published')
    ->tag('sitemap')
    ->handle(...);
// Tagged: ['handler:seo', 'sitemap']
```

The `handler:` prefix is reserved and cannot be used manually via `tag()` or `globalTags()`.

### Priority

Handlers run in priority order — lower numbers run first. Default is `10`.

```php
$seo = $registry->registerHandler('seo');

// Runs first — sanitize early
$seo->hook('cms', 'render-html')
    ->priority(1)
    ->handle(fn (RenderPayload $p) => $p->withHtml(strip_tags($p->html, '<p><a><strong>')));

// Runs last — final formatting pass
$seo->hook('cms', 'render-html')
    ->priority(100)
    ->handle(fn (RenderPayload $p) => $p->withHtml(nl2br($p->html)));
```

### Exclusive handlers

An exclusive handler short-circuits the chain. No lower-priority handlers run after it. This works
on all three hook types.

```php
$cache = $registry->registerHandler('cache');

$cache->hook('cms', 'render-html')
    ->priority(0)
    ->exclusive()
    ->when(fn (RenderPayload $p) => Cache::has("page:{$p->html}"))
    ->handle(fn (RenderPayload $p) => $p->withHtml(Cache::get("page:{$p->html}")));
```

### Conditional handlers

Use `when()` to attach a condition. The handler is skipped (not removed) when the condition returns
false. Skipped handlers do not break the chain — subsequent handlers continue normally.

```php
$dashboard = $registry->registerHandler('dashboard');

// Only run for logged-in users
$dashboard->hook('cms', 'sidebar-widgets')
    ->when(fn (SidebarContext $ctx) => $ctx->viewer->isAuthenticated())
    ->handle(fn (SidebarContext $ctx) => [
        new Widget('Your Drafts', renderDraftsWidget($ctx->viewer)),
    ]);

// Only run in production
$dashboard->hook('cms', 'render-html')
    ->when(fn () => getenv('APP_ENV') === 'production')
    ->handle(fn (RenderPayload $p) => $p->withHtml(minifyHtml($p->html)));
```

### Tag filtering

When invoking a hook, the source can pass a list of tags to only run matching handlers. A handler
matches if it has at least one of the requested tags.

```php
// Only run the SEO handler
$payload = $cms->apply('render-html', $payload, ['handler:seo']);

// Only run handlers with a custom tag
$items = $cms->collectFromHandlers('admin-menu', $context, ['premium']);
```

If no tags are passed (the default), all handlers run as usual. This is useful when the source
wants to target a specific handler - for example, letting the user choose which app handles a
particular action.

### Full handler example

```php
$debug = $registry->registerHandler('debug');

$debug->hook('cms', 'sidebar-widgets')
    ->priority(5)
    ->exclusive()
    ->when(fn (SidebarContext $ctx) => $ctx->viewer->isAdmin())
    ->tag('admin')
    ->handle(fn (SidebarContext $ctx) => [
        new Widget('Debug Panel', renderDebugPanel()),
        new Widget('Cache Stats', renderCacheStats()),
    ]);
```

## Existence Checks

Both sides can check whether the other exists before committing to work.

### Handler side — check if a source exists

Useful for optional integrations where the source package may not be installed:

```php
$minifier = $registry->registerHandler('minifier');

if ($registry->hasSource('cms')) {
    $minifier->hook('cms', 'render-html')
        ->handle(fn (RenderPayload $p) => $p->withHtml(minify($p->html)));
}
```

### Source side — check if anyone is listening

Useful for skipping expensive payload construction when no handlers are registered:

```php
if ($cms->hasHandlers('render-html')) {
    $payload = new RenderPayload($this->buildExpensiveHtml());
    $payload = $cms->apply('render-html', $payload);
}
```

Both methods validate their arguments — `hasHandlers()` throws `SourceNotFoundException` or
`HookPointNotFoundException` if the source or point doesn't exist.

## Introspection

### List all sources

```php
$sources = $registry->sources();
// Returns: array<string, SourceStore>

foreach ($sources as $id => $source) {
    echo "{$id} (class: {$source->class})\n";
}
```

### List hook points for a source

```php
$points = $registry->sources()['cms']->points();
// Returns: array<string, HookPoint>

foreach ($points as $name => $point) {
    echo "{$name}: {$point->type->value} ({$point->payloadClass})\n";
}
// render-html: filter (RenderPayload)
// page-published: action (PageEvent)
// sidebar-widgets: collect (SidebarContext)
```

### List handlers for a point

Handlers are returned sorted by priority (lower first):

```php
$handlers = $registry->sources()['cms']->getHandlers('render-html');
// Returns: list<HandlerEntry>

foreach ($handlers as $handler) {
    echo "priority={$handler->priority}"
       . " exclusive=" . ($handler->exclusive ? 'yes' : 'no')
       . " tags=" . implode(',', $handler->tags)
       . "\n";
}
```

## Error Handling

Latch throws specific exceptions for invalid usage:

| Exception                    | When                                                                              |
| ---------------------------- | --------------------------------------------------------------------------------- |
| `SourceNotFoundException`    | Hooking into, invoking, or checking an unregistered source ID                     |
| `HookPointNotFoundException` | Referencing a point name that wasn't declared on the source                       |
| `HookTypeMismatchException`  | Calling `apply()` on an action point, `dispatch()` on a filter, etc.              |
| `DuplicateHandlerException`  | Calling `registerHandler()` with a name that's already registered                 |
| `DuplicateSourceException`   | Calling `registerSource()` with a name that's already registered                  |
| `ReservedTagException`       | Using the `handler:` prefix in `tag()` or `globalTags()` (reserved for auto-tags) |

```php
use Latch\Exceptions\SourceNotFoundException;
use Latch\Exceptions\HookPointNotFoundException;
use Latch\Exceptions\HookTypeMismatchException;
use Latch\Exceptions\DuplicateHandlerException;
use Latch\Exceptions\DuplicateSourceException;
use Latch\Exceptions\ReservedTagException;

try {
    $cms->apply('some-point', $payload);
} catch (HookPointNotFoundException $e) {
    // "Hook point 'some-point' not found in source 'cms'."
}

try {
    $handler = $registry->registerHandler('seo');
    $handler->hook('cms', 'nonexistent-point');
} catch (HookPointNotFoundException $e) {
    // "Hook point 'nonexistent-point' not found in source 'cms'."
}

try {
    // 'page-published' is an action, not a filter
    $cms->apply('page-published', $payload);
} catch (HookTypeMismatchException $e) {
    // "Hook point 'page-published' in source 'cms' is of type 'action', but was invoked as 'filter'."
}

try {
    $registry->registerHandler('seo');
    $registry->registerHandler('seo'); // already registered
} catch (DuplicateHandlerException $e) {
    // "Handler 'seo' is already registered."
}

try {
    $registry->registerSource('cms');
    $registry->registerSource('cms'); // already registered
} catch (DuplicateSourceException $e) {
    // "Source 'cms' is already registered."
}
```
