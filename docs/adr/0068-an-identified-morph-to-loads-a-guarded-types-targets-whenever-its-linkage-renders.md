# An identified MorphTo loads a guarded type's targets whenever its linkage renders

Core renders an identified `MorphTo`'s linkage from the stored pair and leaves loading the target to the host (core ADR 0189). A pair alone can't tell whether the caller may see the target, so a member type that is guarded on the server, by an Eloquent global scope, a provider of its own, or a `view` check that can refuse a row, has its targets loaded whenever the linkage renders, included or not, in one read per guarded type through that type's provider, and a target that read or the `view` check hides renders as `null`. A type with no such guard keeps the zero-load pair, which is the point of `identifiedBy()`, so the cost lands only where access control needs it.

## Considered options

- Load only on an include, and let unincluded linkage show the pair even when the target is hidden. It kept every linkage free, but leaked the identity of rows the caller can't read, which a guarded to-one never does.

## Consequences

Whether a type is guarded is worked out once per server and type and cached for the process. A relation's own read permission is checked against the parent, so it never needs a load. The targets load whether include batching is on or off, because nothing else can read them, the linkage seam answers only with loaded targets and never reads the relation off the parent, and an include loads every type so `included` can render it.
