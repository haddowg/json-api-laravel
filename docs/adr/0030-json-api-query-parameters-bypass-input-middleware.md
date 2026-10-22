# JSON:API query parameters bypass the input middleware

Laravel's default global middleware rewrite the query bag: `ConvertEmptyStringsToNull` turned
the valid empty fieldset `fields[artists]=` into `null`, which core rejects as a malformed
`400`, and made an empty `filter` value match differently, while `TrimStrings` trimmed filter
values the Symfony bundle receives untouched. The PSR request the controller and the exception
renderer hand to core now takes its query parameters from `parse_str()` of the raw
`QUERY_STRING` (`Http\WireQuery`), the same parser that built `$_GET`, so a value the
middleware left alone arrives unchanged and the same request answers the same on both
frameworks. The query parameters are a wire format whose decoding belongs to core: a filter
value's whitespace or an empty fieldset means something, and no app middleware knows the
grammar well enough to rewrite it safely.

## Considered options

- **Exclude the JSON:API routes from the middleware** (`ConvertEmptyStringsToNull::skipWhen()`,
  `TrimStrings::skipWhen()`). The middleware run before routing, so the skip can't use the
  matched route; it would have to guess from the path, and it would be app configuration
  every install has to get right.
- **Undo only what the two middleware did** (restore `''` where the bag holds `null`). That
  can't recover the whitespace `TrimStrings` removed, and it ties the package to two
  middleware's internals.

## Consequences

An app middleware that deliberately rewrites the query bag of a JSON:API route, say to inject a
default `page[size]`, no longer reaches core. Do that with the resource's paginator defaults or
a lifecycle listener instead. A request built without a `QUERY_STRING` server value keeps its
query bag.
