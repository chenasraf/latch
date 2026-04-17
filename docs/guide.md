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

$registry->source('cms')
    ->filter('render-html', RenderPayload::class)
    ->filter('page-title', TitlePayload::class)
    ->action('page-published', PageEvent::class)
    ->action('page-deleted', PageEvent::class)
    ->collect('sidebar-widgets', SidebarContext::class)
    ->collect('admin-menu', MenuContext::class);
```

You can optionally pass a class name to associate with the source for introspection:

```php
$registry->source('cms', CmsApplication::class);
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
$payload = $registry->apply('cms', 'render-html', $payload);
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

$registry->dispatch('cms', 'page-published', new PageEvent($page, $currentUser));
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

$widgets = $registry->collect('cms', 'sidebar-widgets', new SidebarContext($page, $viewer));

foreach ($widgets as $widget) {
    echo "<div class=\"widget\"><h3>{$widget->title}</h3>{$widget->html}</div>";
}
```

The context argument is optional — pass `null` or omit it if handlers don't need context:

```php
$items = $registry->collect('cms', 'admin-menu');
```

If no handlers are registered, an empty array is returned.

## Handler Side

### Attaching a handler

Use `hook()` to get a builder, configure it, and finalize with `handle()`:

```php
$registry->hook('cms', 'render-html')
    ->handle(fn (RenderPayload $p) => $p->withHtml(
        str_replace('{{year}}', date('Y'), $p->html)
    ));
```

### Priority

Handlers run in priority order — lower numbers run first. Default is `10`.

```php
// Runs first — sanitize early
$registry->hook('cms', 'render-html')
    ->priority(1)
    ->handle(fn (RenderPayload $p) => $p->withHtml(strip_tags($p->html, '<p><a><strong>')));

// Runs last — final formatting pass
$registry->hook('cms', 'render-html')
    ->priority(100)
    ->handle(fn (RenderPayload $p) => $p->withHtml(nl2br($p->html)));
```

### Exclusive handlers

An exclusive handler short-circuits the chain. No lower-priority handlers run after it. This works
on all three hook types.

```php
$registry->hook('cms', 'render-html')
    ->priority(0)
    ->exclusive()
    ->when(fn (RenderPayload $p) => Cache::has("page:{$p->html}"))
    ->handle(fn (RenderPayload $p) => $p->withHtml(Cache::get("page:{$p->html}")));
```

### Conditional handlers

Use `when()` to attach a condition. The handler is skipped (not removed) when the condition returns
false. Skipped handlers do not break the chain — subsequent handlers continue normally.

```php
// Only run for logged-in users
$registry->hook('cms', 'sidebar-widgets')
    ->when(fn (SidebarContext $ctx) => $ctx->viewer->isAuthenticated())
    ->handle(fn (SidebarContext $ctx) => [
        new Widget('Your Drafts', renderDraftsWidget($ctx->viewer)),
    ]);

// Only run in production
$registry->hook('cms', 'render-html')
    ->when(fn () => getenv('APP_ENV') === 'production')
    ->handle(fn (RenderPayload $p) => $p->withHtml(minifyHtml($p->html)));
```

### Tags

Tags are arbitrary strings you can attach to handlers for introspection and filtering.

```php
$registry->hook('cms', 'admin-menu')
    ->tag('analytics', 'premium')
    ->handle(fn (MenuContext $ctx) => [
        new MenuItem('Analytics Dashboard', '/analytics'),
    ]);
```

### Tag filtering

When invoking a hook, the source can pass a list of tags to only run matching handlers. A handler
matches if it has at least one of the requested tags.

```php
// Only run handlers tagged 'analytics'
$items = $registry->collect('cms', 'admin-menu', $context, ['analytics']);

// Same for filters and actions
$payload = $registry->apply('cms', 'render-html', $payload, ['premium']);
$registry->dispatch('cms', 'page-published', $event, ['seo']);
```

If no tags are passed (the default), all handlers run as usual. This is useful when the source
wants to target a specific handler - for example, letting the user choose which app handles a
particular action.

### Full handler example

```php
$registry->hook('cms', 'sidebar-widgets')
    ->priority(5)
    ->exclusive()
    ->when(fn (SidebarContext $ctx) => $ctx->viewer->isAdmin())
    ->tag('admin', 'debug')
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
if ($registry->hasSource('cms')) {
    $registry->hook('cms', 'render-html')
        ->handle(fn (RenderPayload $p) => $p->withHtml(minify($p->html)));
}
```

### Source side — check if anyone is listening

Useful for skipping expensive payload construction when no handlers are registered:

```php
if ($registry->hasHandlers('cms', 'render-html')) {
    $payload = new RenderPayload($this->buildExpensiveHtml());
    $payload = $registry->apply('cms', 'render-html', $payload);
}
```

Both methods validate their arguments — `hasHandlers()` throws `SourceNotFoundException` or
`HookPointNotFoundException` if the source or point doesn't exist.

## Introspection

### List all sources

```php
$sources = $registry->sources();
// Returns: array<string, HookSource>

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
// Returns: list<HookHandler>

foreach ($handlers as $handler) {
    echo "priority={$handler->priority}"
       . " exclusive=" . ($handler->exclusive ? 'yes' : 'no')
       . " tags=" . implode(',', $handler->tags)
       . "\n";
}
```

## Error Handling

Latch throws specific exceptions for invalid usage:

| Exception                    | When                                                                             |
| ---------------------------- | -------------------------------------------------------------------------------- |
| `SourceNotFoundException`    | `hook()`, `apply()`, `dispatch()`, or `collect()` with an unregistered source ID |
| `HookPointNotFoundException` | Referencing a point name that wasn't declared on the source                      |
| `HookTypeMismatchException`  | Calling `apply()` on an action point, `dispatch()` on a filter, etc.             |

```php
use Latch\Exceptions\SourceNotFoundException;
use Latch\Exceptions\HookPointNotFoundException;
use Latch\Exceptions\HookTypeMismatchException;

try {
    $registry->apply('unknown-source', 'some-point', $payload);
} catch (SourceNotFoundException $e) {
    // "Hook source 'unknown-source' not found."
}

try {
    $registry->hook('cms', 'nonexistent-point');
} catch (HookPointNotFoundException $e) {
    // "Hook point 'nonexistent-point' not found in source 'cms'."
}

try {
    // 'page-published' is an action, not a filter
    $registry->apply('cms', 'page-published', $payload);
} catch (HookTypeMismatchException $e) {
    // "Hook point 'page-published' in source 'cms' is of type 'action', but was invoked as 'filter'."
}
```
