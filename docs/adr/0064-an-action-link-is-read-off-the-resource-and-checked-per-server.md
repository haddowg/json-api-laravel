# An action link is read off the resource and checked per server

A resource's `ActionLink` (core ADR 0186) lives in `links()`, which the discovery cache can't hold, so each server's `ActionLinkContributor` now gets every resource-scope action with its `asLink` flag and reads the declared action links off the server's registered resource when it renders. An action links when it sets `asLink` or the resource declares an action link for its path. The ability gate runs first, then a `ConditionallyLinked` handler's `shouldLink()`, then the action link's `when()`. The bundle made the same change in its ADR 0159.

## Consequences

`ServableResourceWarmer` builds each resource's links, so core's duplicate and name-clash errors throw from `jsonapi:optimize`, and it reports an action link that names no resource-scope action of the type on a server the resource is served on. Actions are exposed per server, so a resource served on several servers needs the action on each of them. `ConditionallyLinked` stays for a condition that belongs to the handler.
