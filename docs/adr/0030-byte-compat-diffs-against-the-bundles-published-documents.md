# Byte-compat diffs against the bundle's published documents, projected by the same core

`composer byte-compat` reads the Symfony bundle's music-catalog documents from the `@haddowg/json-api-symfony-specs` npm package, pinned to one exact version, instead of booting the bundle's example kernel from a sibling checkout, so the check no longer installs a second application and compares against what a bundle release actually shipped rather than whatever its default branch holds. The package's `versions.json` names the core that projected it, and the check refuses to diff unless that is the core installed here (exact, ignoring a leading `v`), because documents from two cores differ for reasons unrelated to this package; CI installs that core before it runs, so a core release doesn't break the check while the pinned bundle release still uses the previous one. A pin is an exact, published version, and a prerelease of a bundle pull request is accepted only on a feature pull request, never on `main` or a release pull request.

## Consequences

The response documents the two conformance suites share travel in the same package, under `conformance/`, so they are diffed against the same bundle release.
