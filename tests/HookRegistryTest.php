<?php

declare(strict_types=1);

use Latch\Exceptions\DuplicateHandlerException;
use Latch\Exceptions\DuplicateSourceException;
use Latch\Exceptions\HookPointNotFoundException;
use Latch\Exceptions\HookTypeMismatchException;
use Latch\Exceptions\ReservedTagException;
use Latch\Exceptions\SourceNotFoundException;
use Latch\HookRegistry;
use Latch\HookType;

beforeEach(function () {
    $this->registry = new HookRegistry;
});

// --- Source Registration ---

it('registers a source', function () {
    $source = $this->registry->registerSource('my-app');

    expect($source->id)->toBe('my-app');
    expect($this->registry->sources())->toHaveKey('my-app');
});

it('registers a source with a class', function () {
    $this->registry->registerSource('my-app', 'App\\MyApp');

    expect($this->registry->sources()['my-app']->class)->toBe('App\\MyApp');
});

it('throws when registering a source with the same name twice', function () {
    $this->registry->registerSource('my-app');
    $this->registry->registerSource('my-app');
})->throws(DuplicateSourceException::class);

it('declares filter, action, and collect points on a source', function () {
    $this->registry->registerSource('my-app')
        ->filter('before-render', stdClass::class)
        ->action('user-created', stdClass::class)
        ->collect('menu-items', stdClass::class);

    $source = $this->registry->sources()['my-app'];

    expect($source->points())->toHaveCount(3);
    expect($source->getPoint('before-render')->type)->toBe(HookType::Filter);
    expect($source->getPoint('user-created')->type)->toBe(HookType::Action);
    expect($source->getPoint('menu-items')->type)->toBe(HookType::Collect);
});

// --- Handler Registration ---

it('registers a handler on a hook point', function () {
    $this->registry->registerSource('my-app')->filter('transform', stdClass::class);

    $handler = $this->registry->registerHandler('plugin');
    $handler->hook('my-app', 'transform')
        ->handle(fn (object $p) => $p);

    $source = $this->registry->sources()['my-app'];
    expect($source->getHandlers('transform'))->toHaveCount(1);
});

it('throws when hooking into a non-existent source', function () {
    $handler = $this->registry->registerHandler('plugin');
    $handler->hook('missing', 'anything');
})->throws(SourceNotFoundException::class);

it('throws when hooking into a non-existent point', function () {
    $this->registry->registerSource('my-app');

    $handler = $this->registry->registerHandler('plugin');
    $handler->hook('my-app', 'missing');
})->throws(HookPointNotFoundException::class);

it('throws when registering a handler with the same name twice', function () {
    $this->registry->registerHandler('seo');
    $this->registry->registerHandler('seo');
})->throws(DuplicateHandlerException::class);

// --- Filter Chain ---

it('applies a filter chain in priority order', function () {
    $source = $this->registry->registerSource('app')
        ->filter('transform', stdClass::class);

    $handler = $this->registry->registerHandler('plugin');

    $handler->hook('app', 'transform')
        ->priority(20)
        ->handle(fn (stdClass $p) => (object) ['value' => $p->value.'-second']);

    $handler->hook('app', 'transform')
        ->priority(5)
        ->handle(fn (stdClass $p) => (object) ['value' => $p->value.'-first']);

    $result = $source->apply('transform', (object) ['value' => 'start']);

    expect($result->value)->toBe('start-first-second');
});

it('throws type mismatch when applying filter on action point', function () {
    $source = $this->registry->registerSource('app')
        ->action('event', stdClass::class);

    $source->apply('event', new stdClass);
})->throws(HookTypeMismatchException::class);

// --- Action Dispatch ---

it('dispatches an action to all handlers', function () {
    $source = $this->registry->registerSource('app')
        ->action('event', stdClass::class);

    $calls = [];

    $a = $this->registry->registerHandler('plugin-a');
    $a->hook('app', 'event')
        ->handle(function (stdClass $p) use (&$calls) {
            $calls[] = 'a';
        });

    $b = $this->registry->registerHandler('plugin-b');
    $b->hook('app', 'event')
        ->handle(function (stdClass $p) use (&$calls) {
            $calls[] = 'b';
        });

    $source->dispatch('event', new stdClass);

    expect($calls)->toBe(['a', 'b']);
});

it('throws type mismatch when dispatching action on filter point', function () {
    $source = $this->registry->registerSource('app')
        ->filter('transform', stdClass::class);

    $source->dispatch('transform', new stdClass);
})->throws(HookTypeMismatchException::class);

// --- Collect ---

it('collects contributions from all handlers', function () {
    $source = $this->registry->registerSource('app')
        ->collect('items', stdClass::class);

    $a = $this->registry->registerHandler('plugin-a');
    $a->hook('app', 'items')
        ->handle(fn () => ['a', 'b']);

    $b = $this->registry->registerHandler('plugin-b');
    $b->hook('app', 'items')
        ->handle(fn () => ['c']);

    $result = $source->collectFromHandlers('items');

    expect($result)->toBe(['a', 'b', 'c']);
});

it('passes context to collect handlers', function () {
    $source = $this->registry->registerSource('app')
        ->collect('items', stdClass::class);

    $handler = $this->registry->registerHandler('plugin');
    $handler->hook('app', 'items')
        ->handle(fn (?stdClass $ctx) => [$ctx?->prefix.'-item']);

    $result = $source->collectFromHandlers('items', (object) ['prefix' => 'admin']);

    expect($result)->toBe(['admin-item']);
});

it('throws type mismatch when collecting on filter point', function () {
    $source = $this->registry->registerSource('app')
        ->filter('transform', stdClass::class);

    $source->collectFromHandlers('transform');
})->throws(HookTypeMismatchException::class);

// --- Priority Ordering ---

it('runs handlers in priority order', function () {
    $source = $this->registry->registerSource('app')
        ->action('event', stdClass::class);

    $order = [];

    $handler = $this->registry->registerHandler('plugin');

    $handler->hook('app', 'event')
        ->priority(30)
        ->handle(function () use (&$order) {
            $order[] = 'c';
        });

    $handler->hook('app', 'event')
        ->priority(10)
        ->handle(function () use (&$order) {
            $order[] = 'a';
        });

    $handler->hook('app', 'event')
        ->priority(20)
        ->handle(function () use (&$order) {
            $order[] = 'b';
        });

    $source->dispatch('event', new stdClass);

    expect($order)->toBe(['a', 'b', 'c']);
});

// --- Exclusive Short-Circuit ---

it('stops after an exclusive handler', function () {
    $source = $this->registry->registerSource('app')
        ->action('event', stdClass::class);

    $calls = [];

    $handler = $this->registry->registerHandler('plugin');

    $handler->hook('app', 'event')
        ->priority(1)
        ->exclusive()
        ->handle(function () use (&$calls) {
            $calls[] = 'first';
        });

    $handler->hook('app', 'event')
        ->priority(20)
        ->handle(function () use (&$calls) {
            $calls[] = 'second';
        });

    $source->dispatch('event', new stdClass);

    expect($calls)->toBe(['first']);
});

it('stops after an exclusive filter handler', function () {
    $source = $this->registry->registerSource('app')
        ->filter('transform', stdClass::class);

    $handler = $this->registry->registerHandler('plugin');

    $handler->hook('app', 'transform')
        ->priority(1)
        ->exclusive()
        ->handle(fn (stdClass $p) => (object) ['value' => 'exclusive']);

    $handler->hook('app', 'transform')
        ->priority(20)
        ->handle(fn (stdClass $p) => (object) ['value' => 'should-not-run']);

    $result = $source->apply('transform', (object) ['value' => 'start']);

    expect($result->value)->toBe('exclusive');
});

it('stops after an exclusive collect handler', function () {
    $source = $this->registry->registerSource('app')
        ->collect('items', stdClass::class);

    $handler = $this->registry->registerHandler('plugin');

    $handler->hook('app', 'items')
        ->priority(1)
        ->exclusive()
        ->handle(fn () => ['only-this']);

    $handler->hook('app', 'items')
        ->priority(20)
        ->handle(fn () => ['not-this']);

    $result = $source->collectFromHandlers('items');

    expect($result)->toBe(['only-this']);
});

// --- Conditional Skipping ---

it('skips handler when condition returns false', function () {
    $source = $this->registry->registerSource('app')
        ->action('event', stdClass::class);

    $calls = [];

    $a = $this->registry->registerHandler('plugin-a');
    $a->hook('app', 'event')
        ->when(fn () => false)
        ->handle(function () use (&$calls) {
            $calls[] = 'skipped';
        });

    $b = $this->registry->registerHandler('plugin-b');
    $b->hook('app', 'event')
        ->when(fn () => true)
        ->handle(function () use (&$calls) {
            $calls[] = 'ran';
        });

    $source->dispatch('event', new stdClass);

    expect($calls)->toBe(['ran']);
});

it('skips conditional filter handler without breaking chain', function () {
    $source = $this->registry->registerSource('app')
        ->filter('transform', stdClass::class);

    $a = $this->registry->registerHandler('plugin-a');
    $a->hook('app', 'transform')
        ->priority(1)
        ->when(fn () => false)
        ->handle(fn (stdClass $p) => (object) ['value' => 'skipped']);

    $b = $this->registry->registerHandler('plugin-b');
    $b->hook('app', 'transform')
        ->priority(2)
        ->handle(fn (stdClass $p) => (object) ['value' => $p->value.'-applied']);

    $result = $source->apply('transform', (object) ['value' => 'start']);

    expect($result->value)->toBe('start-applied');
});

// --- Tags ---

it('stores tags on handlers', function () {
    $this->registry->registerSource('app')->action('event', stdClass::class);

    $handler = $this->registry->registerHandler('plugin');
    $hookHandler = $handler->hook('app', 'event')
        ->tag('admin', 'ui')
        ->handle(fn () => null);

    expect($hookHandler->tags)->toBe(['handler:plugin', 'admin', 'ui']);
});

// --- Tag Filtering ---

it('filters action handlers by tag', function () {
    $source = $this->registry->registerSource('app')
        ->action('event', stdClass::class);

    $calls = [];

    $pantry = $this->registry->registerHandler('pantry');
    $pantry->hook('app', 'event')
        ->handle(function () use (&$calls) {
            $calls[] = 'pantry';
        });

    $shopping = $this->registry->registerHandler('shopping-list');
    $shopping->hook('app', 'event')
        ->handle(function () use (&$calls) {
            $calls[] = 'shopping-list';
        });

    $source->dispatch('event', new stdClass, ['handler:pantry']);

    expect($calls)->toBe(['pantry']);
});

it('filters filter handlers by tag', function () {
    $source = $this->registry->registerSource('app')
        ->filter('transform', stdClass::class);

    $pantry = $this->registry->registerHandler('pantry');
    $pantry->hook('app', 'transform')
        ->handle(fn (stdClass $p) => (object) ['value' => $p->value.'-pantry']);

    $other = $this->registry->registerHandler('other');
    $other->hook('app', 'transform')
        ->handle(fn (stdClass $p) => (object) ['value' => $p->value.'-other']);

    $result = $source->apply('transform', (object) ['value' => 'start'], ['handler:pantry']);

    expect($result->value)->toBe('start-pantry');
});

it('filters collect handlers by tag', function () {
    $source = $this->registry->registerSource('app')
        ->collect('items', stdClass::class);

    $pantry = $this->registry->registerHandler('pantry');
    $pantry->hook('app', 'items')
        ->handle(fn () => ['eggs', 'milk']);

    $other = $this->registry->registerHandler('other');
    $other->hook('app', 'items')
        ->handle(fn () => ['nope']);

    $result = $source->collectFromHandlers('items', null, ['handler:pantry']);

    expect($result)->toBe(['eggs', 'milk']);
});

it('runs all handlers when no tag filter is given', function () {
    $source = $this->registry->registerSource('app')
        ->action('event', stdClass::class);

    $calls = [];

    $pantry = $this->registry->registerHandler('pantry');
    $pantry->hook('app', 'event')
        ->handle(function () use (&$calls) {
            $calls[] = 'pantry';
        });

    $other = $this->registry->registerHandler('other');
    $other->hook('app', 'event')
        ->handle(function () use (&$calls) {
            $calls[] = 'other';
        });

    $source->dispatch('event', new stdClass);

    expect($calls)->toBe(['pantry', 'other']);
});

// --- Registered Handlers ---

it('auto-tags hooks with handler name', function () {
    $this->registry->registerSource('app')->action('event', stdClass::class);

    $handler = $this->registry->registerHandler('seo');
    $hookHandler = $handler->hook('app', 'event')
        ->handle(fn () => null);

    expect($hookHandler->tags)->toBe(['handler:seo']);
});

it('auto-tags hooks with handler name and additional tags', function () {
    $this->registry->registerSource('app')->action('event', stdClass::class);

    $handler = $this->registry->registerHandler('seo', ['premium']);
    $hookHandler = $handler->hook('app', 'event')
        ->handle(fn () => null);

    expect($hookHandler->tags)->toBe(['handler:seo', 'premium']);
});

it('applies auto-tags to all hooks from a registered handler', function () {
    $this->registry->registerSource('app')
        ->action('event-a', stdClass::class)
        ->action('event-b', stdClass::class);

    $handler = $this->registry->registerHandler('analytics', ['tracking']);

    $a = $handler->hook('app', 'event-a')->handle(fn () => null);
    $b = $handler->hook('app', 'event-b')->handle(fn () => null);

    expect($a->tags)->toBe(['handler:analytics', 'tracking']);
    expect($b->tags)->toBe(['handler:analytics', 'tracking']);
});

it('merges auto-tags with per-hook tags', function () {
    $this->registry->registerSource('app')->action('event', stdClass::class);

    $handler = $this->registry->registerHandler('seo');
    $hookHandler = $handler->hook('app', 'event')
        ->tag('extra')
        ->handle(fn () => null);

    expect($hookHandler->tags)->toBe(['handler:seo', 'extra']);
});

it('adds global tags after construction', function () {
    $this->registry->registerSource('app')
        ->action('event-a', stdClass::class)
        ->action('event-b', stdClass::class);

    $handler = $this->registry->registerHandler('seo');
    $a = $handler->hook('app', 'event-a')->handle(fn () => null);

    $handler->globalTags('premium', 'v2');
    $b = $handler->hook('app', 'event-b')->handle(fn () => null);

    expect($a->tags)->toBe(['handler:seo']);
    expect($b->tags)->toBe(['handler:seo', 'premium', 'v2']);
});

it('filters by handler auto-tag', function () {
    $source = $this->registry->registerSource('app')
        ->action('event', stdClass::class);

    $calls = [];

    $seo = $this->registry->registerHandler('seo');
    $seo->hook('app', 'event')
        ->handle(function () use (&$calls) {
            $calls[] = 'seo';
        });

    $analytics = $this->registry->registerHandler('analytics');
    $analytics->hook('app', 'event')
        ->handle(function () use (&$calls) {
            $calls[] = 'analytics';
        });

    $source->dispatch('event', new stdClass, ['handler:seo']);

    expect($calls)->toBe(['seo']);
});

// --- Reserved Tag Protection ---

it('throws when manually using handler: tag prefix', function () {
    $this->registry->registerSource('app')->action('event', stdClass::class);

    $handler = $this->registry->registerHandler('plugin');
    $handler->hook('app', 'event')
        ->tag('handler:fake')
        ->handle(fn () => null);
})->throws(ReservedTagException::class);

it('throws when passing handler: tag to registerHandler additional tags', function () {
    $this->registry->registerHandler('seo', ['handler:spoof']);
})->throws(ReservedTagException::class);

it('throws when passing handler: tag to globalTags', function () {
    $handler = $this->registry->registerHandler('seo');
    $handler->globalTags('handler:spoof');
})->throws(ReservedTagException::class);

// --- Introspection ---

it('lists all sources', function () {
    $this->registry->registerSource('app-a');
    $this->registry->registerSource('app-b');

    $sources = $this->registry->sources();

    expect($sources)->toHaveCount(2);
    expect(array_keys($sources))->toBe(['app-a', 'app-b']);
});

it('lists hook points per source', function () {
    $this->registry->registerSource('app')
        ->filter('f1', stdClass::class)
        ->action('a1', stdClass::class);

    $points = $this->registry->sources()['app']->points();

    expect($points)->toHaveCount(2);
    expect($points['f1']->type)->toBe(HookType::Filter);
    expect($points['a1']->type)->toBe(HookType::Action);
});

it('lists handlers per point', function () {
    $this->registry->registerSource('app')->filter('transform', stdClass::class);

    $handler = $this->registry->registerHandler('plugin');

    $handler->hook('app', 'transform')
        ->priority(5)
        ->handle(fn ($p) => $p);

    $handler->hook('app', 'transform')
        ->priority(15)
        ->handle(fn ($p) => $p);

    $handlers = $this->registry->sources()['app']->getHandlers('transform');

    expect($handlers)->toHaveCount(2);
    expect($handlers[0]->priority)->toBe(5);
    expect($handlers[1]->priority)->toBe(15);
});

// --- Existence Checks ---

it('reports whether a source exists', function () {
    expect($this->registry->hasSource('app'))->toBeFalse();

    $this->registry->registerSource('app');

    expect($this->registry->hasSource('app'))->toBeTrue();
});

it('reports whether a point has handlers', function () {
    $source = $this->registry->registerSource('app')
        ->filter('transform', stdClass::class);

    expect($source->hasHandlers('transform'))->toBeFalse();

    $handler = $this->registry->registerHandler('plugin');
    $handler->hook('app', 'transform')
        ->handle(fn ($p) => $p);

    expect($source->hasHandlers('transform'))->toBeTrue();
});

it('throws HookPointNotFoundException when checking handlers on missing point', function () {
    $source = $this->registry->registerSource('app');

    $source->hasHandlers('missing');
})->throws(HookPointNotFoundException::class);

// --- Exception Cases ---

it('throws HookPointNotFoundException for apply on missing point', function () {
    $source = $this->registry->registerSource('app');

    $source->apply('missing', new stdClass);
})->throws(HookPointNotFoundException::class);

it('returns payload unchanged when no handlers registered', function () {
    $source = $this->registry->registerSource('app')
        ->filter('transform', stdClass::class);

    $payload = (object) ['value' => 'unchanged'];
    $result = $source->apply('transform', $payload);

    expect($result->value)->toBe('unchanged');
});

it('returns empty array when no collect handlers registered', function () {
    $source = $this->registry->registerSource('app')
        ->collect('items', stdClass::class);

    $result = $source->collectFromHandlers('items');

    expect($result)->toBe([]);
});
