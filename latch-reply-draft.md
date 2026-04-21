Hey @christianlupus,

I went ahead and built an initial implementation of the shared hooking system we talked about. It's a standalone Composer package called **Latch**: <https://github.com/chenasraf/latch>

It's basically the common Composer library you described. Apps can declare hook points and attach handlers without knowing anything about each other. The registry is a singleton so all apps on the same instance share the same state.

I'd love to get your input on the design and see where we can take this.

### How it works

**The handshake problem:** Each app can check if the other one is there before doing anything. Pantry can do `$registry->hasSource('cookbook')` and just skip its hooks if Cookbook isn't installed. Cookbook can do the same the other way around with `$source->hasHandlers('send-to-shopping-list')` as a quick check.

**Three types of hooks:** Latch has three types that work differently depending on what you need:

- **Filters** - you pass some data through a chain and each handler can change it along the way
- **Actions** - just a notification that something happened, nobody expects a response (similar to Nextcloud event registration, just not NC-only so it's possible to use with other systems)
- **Collectors** - ask everyone to chip in their items and get back one combined list

### How the shopping list could actually work

Let's say a user is looking at a recipe in Cookbook and hits a "Send to shopping list" button. Here's what could happen behind the scenes:

1. Cookbook registers itself as a source (`$source = $registry->registerSource('cookbook')`) and fires a `collect` hook, basically asking "who wants to handle shopping list items?"
2. Pantry has registered a handler for that hook. It receives the recipe's ingredient list and creates a new checklist (or adds to an existing one) on the user's Pantry board.
3. The user opens Pantry and sees their shopping list ready to go, with all the ingredients from the recipe.

If Tobias's shopping list app is also installed, it could register its own handler for the same hook - and the user could pick which app handles their shopping list, or both could. Neither Cookbook nor Pantry needs to know about the third app at all.

Going the other way works too. Say Pantry wants to show a "find recipes with these ingredients" button. It checks `hasSource('cookbook')`, and if Cookbook is there, it fires a hook that Cookbook handles - maybe returning matching recipes or just a link to a filtered view.

**What if the user only wants one app to handle it?** Say both Pantry and Tobias's shopping list app are installed, but the user only wants Cookbook to send items to Pantry. Latch has a `registerHandler` pattern for this - each app registers itself with a name:

```php
$pantry = $registry->registerHandler('pantry');
$pantry->hook('cookbook', 'send-to-shopping-list')->handle(...);
```

This auto-tags all of Pantry's hooks with `handler:pantry`. When Cookbook fires the hook, it can pass a tag filter to only run a specific handler:

```php
$cookbook->dispatch('send-to-shopping-list', $payload, ['handler:pantry']);
```

The tag could come from a user setting in Cookbook like "preferred shopping list app", or from a choice dialog when the user clicks the button.

Discovery can be done like this: Cookbook first fires a `collect` hook asking "who can handle shopping lists?", maybe via `.collect(`shopping-list-providers`)` - each app responds with its name+label. Cookbook shows the user a picker, then fires a second hook targeting only the chosen app. This gives the user an explicit choice in the UI without either app needing to know about the other.

### How it works across Nextcloud apps

One thing I had to work around is the autoloader problem - if each app bundles its own copy of Latch via `composer require`, they get separate class hierarchies and can't share a registry instance directly.

The solution routes hooks through NC's `IEventDispatcher`, which is shared across all apps. Each app bundles its own copy of Latch, but cross-app communication goes through NC events with conventional names like `latch:cookbook:recipe-shared`. Payloads cross the autoloader boundary because PHP resolves methods on the actual object at runtime, not the type hint - so even though the classes come from different autoloaders, the method calls work fine.

From the app developer's perspective the API is the same as plain Latch - the only difference is using `LatchBootstrap::registry()` instead of `new HookRegistry()`:

```php
// Cookbook (source app):
$registry = LatchBootstrap::registry();
$this->cookbook = $registry->registerSource('cookbook')
    ->action('recipe-shared', stdClass::class);

// Pantry (handler app):
$registry = LatchBootstrap::registry();
$handler = $registry->registerHandler('pantry');
$handler->hook('cookbook', 'recipe-shared')
    ->handle(fn ($payload) => PantryService::createShoppingList($payload->ingredients));
```

All three hook types (actions, filters, collectors) work through the bridge. I'd love to hear if this approach makes sense to you or if there's a better NC-native pattern I'm missing.

---

The library is still early, so this is a good time to shape it around real use cases like ours. If anything about the design doesn't fit how Cookbook works, or if you have ideas about what the payloads should look like, I'm happy to hear it.

Also happy to set up both apps on a dev instance and try a quick prototype if you want to see it in action.
