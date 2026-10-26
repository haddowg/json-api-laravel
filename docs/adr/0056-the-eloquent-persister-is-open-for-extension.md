# The Eloquent persister is open for extension

Building a new entity differently or replacing delete with a service call meant writing a whole persister, and one that only delegated to the reference persister lost its transaction, soft-delete and current-owner capabilities. `EloquentDataPersister` is no longer final, so an app overrides `instantiate()` or `delete()` in a subclass registered for its types and inherits the rest. A per-type callback API was the alternative, but subclassing is the Laravel habit and adds no new concept.
