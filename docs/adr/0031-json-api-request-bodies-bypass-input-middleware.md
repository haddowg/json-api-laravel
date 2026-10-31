# JSON:API query parameters and bodies bypass Laravel's input middleware

Laravel's default `TrimStrings` and `ConvertEmptyStringsToNull` rewrite the input bags, which turns an empty fieldset into a malformed `400`, trims filter values and padded attributes, and turns `""` into `null`. The PSR request handed to core takes its query from `parse_str()` of the raw query string and, for a JSON body, a fresh decode of the raw content, because these are wire formats core decodes and validates and no app middleware knows their grammar well enough to rewrite them.

## Considered options

- **Exclude JSON:API routes with `skipWhen()`.** The middleware run before routing, so the skip would guess from the path and every app would have to configure it.
- **Undo the rewrite.** It can't recover trimmed whitespace and couples the package to two middleware's internals.

## Consequences

An app middleware that deliberately rewrites a JSON:API query or body no longer reaches core; a paginator default, resource hook or lifecycle listener does that instead.
