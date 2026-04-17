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
$registry->source('cms')
    ->filter('render-html', RenderPayload::class)
    ->action('page-published', PageEvent::class)
    ->collect('nav-items', NavContext::class);

// 2. Handlers attach to those points
$registry->hook('cms', 'render-html')
    ->priority(5)
    ->handle(fn (RenderPayload $p) => $p->withHtml(minify($p->html)));

$registry->hook('cms', 'nav-items')
    ->when(fn (NavContext $ctx) => $ctx->user->isAdmin())
    ->handle(fn (NavContext $ctx) => [new NavItem('Admin', '/admin')]);

// 3. Source invokes at runtime
$payload = $registry->apply('cms', 'render-html', new RenderPayload($html));
$registry->dispatch('cms', 'page-published', new PageEvent($page, $user));
$navItems = $registry->collect('cms', 'nav-items', new NavContext($user));
```

## Hook Types

| Type    | Source invokes | Handler receives           | Handler returns                 |
| ------- | -------------- | -------------------------- | ------------------------------- |
| Filter  | `apply()`      | The payload object         | A modified payload (chained)    |
| Action  | `dispatch()`   | The payload object         | Nothing (return value ignored)  |
| Collect | `collect()`    | An optional context object | An array of items (merged flat) |

## Handler Options

```php
$registry->hook('source', 'point')
    ->priority(5)           // Lower runs first (default: 10)
    ->exclusive()           // Short-circuits remaining handlers after this one
    ->when(fn ($p) => ...)  // Skip handler if condition returns false
    ->tag('admin', 'ui')   // Tags for introspection and filtering
    ->handle(fn ($p) => ...);
```

## Existence Checks

```php
$registry->hasSource('cms');                    // Does this source exist?
$registry->hasHandlers('cms', 'render-html');   // Does anyone handle this point?
```

## Framework Integration

| Framework | Setup |
| --------- | ----- |
| Laravel   | Auto-discovered — inject `HookRegistryInterface` anywhere |
| Nextcloud | Call `LatchBootstrap::register()` / `::registry()` in your `Application` |
| Plain PHP | `new HookRegistry()` |

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
