# An identified MorphTo loads a guarded type's targets whenever its linkage renders

Core renders an identified `MorphTo`'s linkage from the stored `[type, id]` pair and leaves loading the target to the host, but a pair alone can't tell whether the caller may see it. A member type guarded on the server (a global scope, its own provider, or a `view` check that can refuse) has its targets loaded whenever the linkage renders, in one read per type, and a hidden target renders as `null`; an unguarded type keeps the zero-load pair.

## Considered options

- **Load only on an include.** Every linkage stays free, but unincluded linkage leaks the identity of rows the caller can't read.
