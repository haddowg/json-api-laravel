# JSON:API request bodies bypass the input middleware

For a JSON body Laravel's input bag is the decoded document, and the default global
`TrimStrings` and `ConvertEmptyStringsToNull` middleware rewrite it in place. The PSR bridge
copied that bag in as the parsed body, so every write in a default app reached core altered:
`"title": "  Spaced  "` was stored as `Spaced`, and `"status": ""` became `null` and failed
validation with "The status field must have a value." The Symfony bundle stores both as sent.
The PSR request the controller and the exception renderer hand to core now carries a fresh
`json_decode()` of `$request->getContent()` as its parsed body whenever Laravel treats the body
as JSON (`Http\WireBody`). This is the same reasoning as ADR 0070: a JSON:API document is a
wire format core decodes and validates itself, and an empty string or a padded one is a value
the client chose.

The rule covers every path that reads a body, because they all build their PSR request in
`JsonApiController::jsonApiRequest()`: CRUD writes, relationship mutations, a Document-input
action, and an Atomic Operations batch, whose sub-operations are cut from the batch's parsed
body. A Raw-input action reads the body stream, which was always the raw content; its
`getParsedBody()` now matches those bytes too. A None-input action never reads the body.

## Considered options

- **Skip the middleware for JSON:API routes** (`skipWhen()`). The middleware run before routing,
  so the skip would have to guess from the path, and every app would have to configure it.
- **Leave the bridge's parsed body empty and let core decode the raw body.** That is the
  bundle's path, but core's `getParsedBody()` returns a decoded scalar body (`"x"`) against its
  `array|object|null` return type, which is a `500`. Laravel's array cast turns the scalar into
  a list core rejects with a `400`, so the package keeps that answer.

## Consequences

Malformed JSON gets the same `400 REQUEST_BODY_INVALID_JSON` as before: the decode fails, the
parsed body stays empty and core decodes the raw body and raises its own error. A scalar body
keeps Laravel's parsed bag and its `400 REQUIRED_TOP_LEVEL_MEMBERS_MISSING`. A plain
`application/json` body was already decoded from the raw content by the bridge and is left as
is. An app middleware that deliberately rewrites a JSON:API body, say to default an attribute,
no longer reaches core; use a resource hook or a lifecycle listener instead.
