# Latch

A cross-package hook/filter registry system for PHP. Apps register themselves as "hook sources" and
declare typed extension points. Other packages attach handlers to those points. The source app
queries the registry at runtime to collect, transform, or broadcast through those handlers.

## Requirements

- PHP ^8.2

## Installation

```bash
composer require chenasraf/latch
```

## Quick Start

```php
use Latch\HookRegistry;

$registry = new HookRegistry();

// 1. Source declares extension points
$cms = $registry->registerSource('cms')
    ->filter('render-html', RenderPayload::class)
    ->action('page-published', PageEvent::class)
    ->collect('nav-items', NavContext::class);

// 2. Register a named handler and attach to those points
$seo = $registry->registerHandler('seo');

$seo->hook('cms', 'render-html')
    ->priority(5)
    ->handle(fn (RenderPayload $p) => $p->withHtml(minify($p->html)));

$seo->hook('cms', 'nav-items')
    ->when(fn (NavContext $ctx) => $ctx->user->isAdmin())
    ->handle(fn (NavContext $ctx) => [new NavItem('Admin', '/admin')]);

// 3. Source invokes at runtime
$payload = $cms->apply('render-html', new RenderPayload($html));
$cms->dispatch('page-published', new PageEvent($page, $user));
$navItems = $cms->collectFromHandlers('nav-items', new NavContext($user));
```

## Hook Types

| Type    | Source invokes | Handler receives           | Handler returns                 |
| ------- | -------------- | -------------------------- | ------------------------------- |
| Filter  | `apply()`              | The payload object         | A modified payload (chained)    |
| Action  | `dispatch()`           | The payload object         | Nothing (return value ignored)  |
| Collect | `collectFromHandlers()`| An optional context object | An array of items (merged flat) |

## Handler Options

```php
$handler = $registry->registerHandler('my-plugin');

$handler->hook('source', 'point')
    ->priority(5)           // Lower runs first (default: 10)
    ->exclusive()           // Short-circuits remaining handlers after this one
    ->when(fn ($p) => ...)  // Skip handler if condition returns false
    ->tag('admin', 'ui')   // Additional tags for introspection and filtering
    ->handle(fn ($p) => ...);
```

All hooks are automatically tagged with `handler:{name}`. Sources can target specific handlers:

```php
$cms->dispatch('page-published', $event, ['handler:seo']);
```

Each handler name can only be registered once. Pass the `HookHandler` instance via DI to
reuse it across your package.

## Capability Discovery

Handlers can declare capability tags at registration. The registry can then be queried to find
handlers that provide a given capability — without knowing their names in advance.

```php
// Handlers declare what they provide
$registry->registerHandler('seo', ['content-enhancement']);
$registry->registerHandler('minifier', ['content-enhancement']);
$registry->registerHandler('analytics', ['tracking']);

// Source discovers who can do "content-enhancement"
$providers = $registry->handlersByTag('content-enhancement');
// → [HandlerInfo('seo'), HandlerInfo('minifier')]

// Each HandlerInfo exposes name and tags
foreach ($providers as $provider) {
    echo "{$provider->name} — " . implode(', ', $provider->tags) . "\n";
}
```

This enables a discovery pattern where a source app can find compatible handlers at runtime,
present them to the user, and target the chosen one via its `handler:{name}` tag.

## Existence Checks

```php
$registry->hasSource('cms');          // Does this source exist?
$cms->hasHandlers('render-html');    // Does anyone handle this point?
```

## Framework Integration

| Framework | Setup |
| --------- | ----- |
| Laravel   | Auto-discovered — inject `HookRegistryInterface` anywhere |
| Nextcloud | Each app bundles Latch; use `LatchBootstrap::registry()` for cross-app hooks via NC events |
| Plain PHP | `HookRegistry::getInstance()` for shared singleton, or `new HookRegistry()` |

## Documentation

- **[Guide](docs/guide.md)** — Full API reference for sources, handlers, introspection, and error handling
- **[Examples](docs/examples.md)** — End-to-end examples and framework integration walkthroughs

## Development

```bash
make install       # Install dependencies
make install-hooks # Set up lefthook git hooks
make test          # Run tests (Pest)
make analyze       # Static analysis (PHPStan level 8)
make fix           # Code style (Laravel Pint)
```

## License

MIT
