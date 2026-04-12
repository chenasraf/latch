<?php

declare(strict_types=1);

use Latch\Exceptions\HookPointNotFoundException;
use Latch\Exceptions\HookTypeMismatchException;
use Latch\Exceptions\SourceNotFoundException;
use Latch\HookRegistry;
use Latch\HookType;

beforeEach(function () {
    $this->registry = new HookRegistry;
});

// --- Source Registration ---

it('registers a source', function () {
    $this->registry->source('my-app');

    expect($this->registry->sources())->toHaveKey('my-app');
    expect($this->registry->sources()['my-app']->id)->toBe('my-app');
});

it('registers a source with a class', function () {
    $this->registry->source('my-app', 'App\\MyApp');

    expect($this->registry->sources()['my-app']->class)->toBe('App\\MyApp');
});

it('declares filter, action, and collect points on a source', function () {
    $this->registry->source('my-app')
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
    $this->registry->source('my-app')->filter('transform', stdClass::class);

    $this->registry->hook('my-app', 'transform')
        ->handle(fn (object $p) => $p);

    $source = $this->registry->sources()['my-app'];
    expect($source->getHandlers('transform'))->toHaveCount(1);
});

it('throws when hooking into a non-existent source', function () {
    $this->registry->hook('missing', 'anything');
})->throws(SourceNotFoundException::class);

it('throws when hooking into a non-existent point', function () {
    $this->registry->source('my-app');

    $this->registry->hook('my-app', 'missing');
})->throws(HookPointNotFoundException::class);

// --- Filter Chain ---

it('applies a filter chain in priority order', function () {
    $this->registry->source('app')->filter('transform', stdClass::class);

    $this->registry->hook('app', 'transform')
        ->priority(20)
        ->handle(fn (stdClass $p) => (object) ['value' => $p->value.'-second']);

    $this->registry->hook('app', 'transform')
        ->priority(5)
        ->handle(fn (stdClass $p) => (object) ['value' => $p->value.'-first']);

    $result = $this->registry->apply('app', 'transform', (object) ['value' => 'start']);

    expect($result->value)->toBe('start-first-second');
});

it('throws type mismatch when applying filter on action point', function () {
    $this->registry->source('app')->action('event', stdClass::class);

    $this->registry->apply('app', 'event', new stdClass);
})->throws(HookTypeMismatchException::class);

// --- Action Dispatch ---

it('dispatches an action to all handlers', function () {
    $this->registry->source('app')->action('event', stdClass::class);

    $calls = [];
    $this->registry->hook('app', 'event')
        ->handle(function (stdClass $p) use (&$calls) {
            $calls[] = 'a';
        });

    $this->registry->hook('app', 'event')
        ->handle(function (stdClass $p) use (&$calls) {
            $calls[] = 'b';
        });

    $this->registry->dispatch('app', 'event', new stdClass);

    expect($calls)->toBe(['a', 'b']);
});

it('throws type mismatch when dispatching action on filter point', function () {
    $this->registry->source('app')->filter('transform', stdClass::class);

    $this->registry->dispatch('app', 'transform', new stdClass);
})->throws(HookTypeMismatchException::class);

// --- Collect ---

it('collects contributions from all handlers', function () {
    $this->registry->source('app')->collect('items', stdClass::class);

    $this->registry->hook('app', 'items')
        ->handle(fn () => ['a', 'b']);

    $this->registry->hook('app', 'items')
        ->handle(fn () => ['c']);

    $result = $this->registry->collect('app', 'items');

    expect($result)->toBe(['a', 'b', 'c']);
});

it('passes context to collect handlers', function () {
    $this->registry->source('app')->collect('items', stdClass::class);

    $this->registry->hook('app', 'items')
        ->handle(fn (?stdClass $ctx) => [$ctx?->prefix.'-item']);

    $result = $this->registry->collect('app', 'items', (object) ['prefix' => 'admin']);

    expect($result)->toBe(['admin-item']);
});

it('throws type mismatch when collecting on filter point', function () {
    $this->registry->source('app')->filter('transform', stdClass::class);

    $this->registry->collect('app', 'transform');
})->throws(HookTypeMismatchException::class);

// --- Priority Ordering ---

it('runs handlers in priority order', function () {
    $this->registry->source('app')->action('event', stdClass::class);

    $order = [];

    $this->registry->hook('app', 'event')
        ->priority(30)
        ->handle(function () use (&$order) {
            $order[] = 'c';
        });

    $this->registry->hook('app', 'event')
        ->priority(10)
        ->handle(function () use (&$order) {
            $order[] = 'a';
        });

    $this->registry->hook('app', 'event')
        ->priority(20)
        ->handle(function () use (&$order) {
            $order[] = 'b';
        });

    $this->registry->dispatch('app', 'event', new stdClass);

    expect($order)->toBe(['a', 'b', 'c']);
});

// --- Exclusive Short-Circuit ---

it('stops after an exclusive handler', function () {
    $this->registry->source('app')->action('event', stdClass::class);

    $calls = [];

    $this->registry->hook('app', 'event')
        ->priority(1)
        ->exclusive()
        ->handle(function () use (&$calls) {
            $calls[] = 'first';
        });

    $this->registry->hook('app', 'event')
        ->priority(20)
        ->handle(function () use (&$calls) {
            $calls[] = 'second';
        });

    $this->registry->dispatch('app', 'event', new stdClass);

    expect($calls)->toBe(['first']);
});

it('stops after an exclusive filter handler', function () {
    $this->registry->source('app')->filter('transform', stdClass::class);

    $this->registry->hook('app', 'transform')
        ->priority(1)
        ->exclusive()
        ->handle(fn (stdClass $p) => (object) ['value' => 'exclusive']);

    $this->registry->hook('app', 'transform')
        ->priority(20)
        ->handle(fn (stdClass $p) => (object) ['value' => 'should-not-run']);

    $result = $this->registry->apply('app', 'transform', (object) ['value' => 'start']);

    expect($result->value)->toBe('exclusive');
});

it('stops after an exclusive collect handler', function () {
    $this->registry->source('app')->collect('items', stdClass::class);

    $this->registry->hook('app', 'items')
        ->priority(1)
        ->exclusive()
        ->handle(fn () => ['only-this']);

    $this->registry->hook('app', 'items')
        ->priority(20)
        ->handle(fn () => ['not-this']);

    $result = $this->registry->collect('app', 'items');

    expect($result)->toBe(['only-this']);
});

// --- Conditional Skipping ---

it('skips handler when condition returns false', function () {
    $this->registry->source('app')->action('event', stdClass::class);

    $calls = [];

    $this->registry->hook('app', 'event')
        ->when(fn () => false)
        ->handle(function () use (&$calls) {
            $calls[] = 'skipped';
        });

    $this->registry->hook('app', 'event')
        ->when(fn () => true)
        ->handle(function () use (&$calls) {
            $calls[] = 'ran';
        });

    $this->registry->dispatch('app', 'event', new stdClass);

    expect($calls)->toBe(['ran']);
});

it('skips conditional filter handler without breaking chain', function () {
    $this->registry->source('app')->filter('transform', stdClass::class);

    $this->registry->hook('app', 'transform')
        ->priority(1)
        ->when(fn () => false)
        ->handle(fn (stdClass $p) => (object) ['value' => 'skipped']);

    $this->registry->hook('app', 'transform')
        ->priority(2)
        ->handle(fn (stdClass $p) => (object) ['value' => $p->value.'-applied']);

    $result = $this->registry->apply('app', 'transform', (object) ['value' => 'start']);

    expect($result->value)->toBe('start-applied');
});

// --- Tags ---

it('stores tags on handlers', function () {
    $this->registry->source('app')->action('event', stdClass::class);

    $handler = $this->registry->hook('app', 'event')
        ->tag('admin', 'ui')
        ->handle(fn () => null);

    expect($handler->tags)->toBe(['admin', 'ui']);
});

// --- Introspection ---

it('lists all sources', function () {
    $this->registry->source('app-a');
    $this->registry->source('app-b');

    $sources = $this->registry->sources();

    expect($sources)->toHaveCount(2);
    expect(array_keys($sources))->toBe(['app-a', 'app-b']);
});

it('lists hook points per source', function () {
    $this->registry->source('app')
        ->filter('f1', stdClass::class)
        ->action('a1', stdClass::class);

    $points = $this->registry->sources()['app']->points();

    expect($points)->toHaveCount(2);
    expect($points['f1']->type)->toBe(HookType::Filter);
    expect($points['a1']->type)->toBe(HookType::Action);
});

it('lists handlers per point', function () {
    $this->registry->source('app')->filter('transform', stdClass::class);

    $this->registry->hook('app', 'transform')
        ->priority(5)
        ->handle(fn ($p) => $p);

    $this->registry->hook('app', 'transform')
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

    $this->registry->source('app');

    expect($this->registry->hasSource('app'))->toBeTrue();
});

it('reports whether a point has handlers', function () {
    $this->registry->source('app')->filter('transform', stdClass::class);

    expect($this->registry->hasHandlers('app', 'transform'))->toBeFalse();

    $this->registry->hook('app', 'transform')
        ->handle(fn ($p) => $p);

    expect($this->registry->hasHandlers('app', 'transform'))->toBeTrue();
});

it('throws SourceNotFoundException when checking handlers on missing source', function () {
    $this->registry->hasHandlers('missing', 'anything');
})->throws(SourceNotFoundException::class);

it('throws HookPointNotFoundException when checking handlers on missing point', function () {
    $this->registry->source('app');

    $this->registry->hasHandlers('app', 'missing');
})->throws(HookPointNotFoundException::class);

// --- Exception Cases ---

it('throws SourceNotFoundException for apply on missing source', function () {
    $this->registry->apply('missing', 'anything', new stdClass);
})->throws(SourceNotFoundException::class);

it('throws SourceNotFoundException for dispatch on missing source', function () {
    $this->registry->dispatch('missing', 'anything', new stdClass);
})->throws(SourceNotFoundException::class);

it('throws SourceNotFoundException for collect on missing source', function () {
    $this->registry->collect('missing', 'anything');
})->throws(SourceNotFoundException::class);

it('throws HookPointNotFoundException for apply on missing point', function () {
    $this->registry->source('app');

    $this->registry->apply('app', 'missing', new stdClass);
})->throws(HookPointNotFoundException::class);

it('returns payload unchanged when no handlers registered', function () {
    $this->registry->source('app')->filter('transform', stdClass::class);

    $payload = (object) ['value' => 'unchanged'];
    $result = $this->registry->apply('app', 'transform', $payload);

    expect($result->value)->toBe('unchanged');
});

it('returns empty array when no collect handlers registered', function () {
    $this->registry->source('app')->collect('items', stdClass::class);

    $result = $this->registry->collect('app', 'items');

    expect($result)->toBe([]);
});
