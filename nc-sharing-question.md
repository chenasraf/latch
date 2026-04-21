Hey @christianlupus,

I've been poking at how two NC apps could share state through a common Composer library, and I hit a wall I wanted to ask you about.

The issue is that each app has its own autoloader and its own `vendor/`, so if Cookbook and Pantry both `composer require` the same package, they get two completely separate copies. A singleton in one app can't see anything the other app registered — they're different singletons from different autoloaders.

The best workaround I found so far is routing everything through NC's `IEventDispatcher`. Each app bundles its own copy of the library but talks to the other through NC events with agreed-upon names (like `latch:cookbook:recipe-shared`). It works because PHP resolves methods on the actual object, not the type hint, so payloads cross the autoloader boundary fine.

The other option would be a small dedicated NC app that holds the shared instance, but that adds an extra install step which feels like overkill.

Do you know if there's a more native way to share state across apps? Some global registry apps can write to, or a pattern you've seen other apps use for this? Curious how Cookbook handles (or plans to handle) talking to other apps in general.

Any thoughts welcome — want to make sure I'm not missing something obvious before building too much on top of this.
