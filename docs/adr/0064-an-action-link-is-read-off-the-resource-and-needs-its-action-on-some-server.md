# An action link is read off the resource and needs its action on some server

A resource's `ActionLink` (core ADR 0186) lives in `links()`, which the discovery cache can't hold, so each server's `ActionLinkContributor` now gets every resource-scope action with its `asLink` flag and reads the declared action links off the server's registered resource when it renders. An action links when it sets `asLink` or the resource declares an action link for its path. The ability gate runs first, then a `ConditionallyLinked` handler's `shouldLink()`, then the action link's `when()`. The bundle made the same change in its ADR 0159.

## Consequences

`ServableResourceWarmer` builds each resource's links, so core's duplicate and name-clash errors throw from `jsonapi:optimize`. Actions are exposed per server, and one resource is often served on several, so a server without the named action leaves the link out of its resources and its document. The warmer, which sees every server, reports an action link only when no server serving the type has the action, which still catches a typo. `ConditionallyLinked` stays for a condition that belongs to the handler.
