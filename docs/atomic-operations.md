# Atomic operations

The package supports the JSON:API **Atomic Operations** extension: a batch of create / update
/ delete / relationship operations submitted to one endpoint and applied in a single database
transaction — all succeed or all roll back (PLAN decision 12).

## Enabling it

It is opt-in. Turn it on in `config/jsonapi.php`:

```php
'atomic_operations' => [
    'enabled' => true,
    'path'    => '/operations',
],
```

That registers `POST /operations` (per server, under the server prefix). The example enables
it at `/operations`.

A batch only reaches the types of the server it is posted to. An operation on a type that server
does not serve, by `ref`, `href` or `data.type`, refuses the whole batch with a `404`
(`ATOMIC_TARGET_TYPE_UNKNOWN`), the same as a direct request to a route the server doesn't have.

## The request

Send the atomic media type and an `atomic:operations` array:

```http
POST /api/operations
Content-Type: application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"
Accept:       application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"

{
  "atomic:operations": [
    { "op": "add",    "data": { "type": "artists", "attributes": { "name": "Boards of Canada" } } },
    { "op": "add",    "data": { "type": "albums",  "attributes": { "title": "Music Has the Right to Children" },
                                "relationships": { "artist": { "data": { "type": "artists", "lid": "a1" } } } } },
    { "op": "update", "ref": { "type": "albums", "id": "1" }, "data": { "type": "albums", "id": "1", "attributes": { "explicit": true } } },
    { "op": "remove", "ref": { "type": "albums", "id": "2" } }
  ]
}
```

## Local ids (`lid`)

A `lid` (local id) lets one operation reference a resource created earlier **in the same
batch**: the first `add` mints an artist under `lid: "a1"`; the second references it as its
`artist` relationship. The package's lid registry resolves the reference once the referent is
persisted, so the whole graph is created in one transaction.

## The transaction and deferred work

The batch runs inside `DB::transaction`. **After\*** JSON:API events/hooks are **deferred to
after commit** — so a post-commit side effect never fires for an operation that a later
failure rolls back. Note the split:

> [!NOTE]
> **Eloquent model events fire mid-transaction** (on each `$model->save()`), while the
> package's After\* events/hooks defer to after the batch commits. If a side effect must not
> run on a rolled-back operation, put it in an After\* [hook](lifecycle-hooks.md), not a
> model observer. See [eloquent](eloquent.md#eloquent-model-events-still-fire).

## OAuth scopes

With [scope enforcement](scopes.md) on, the batch needs the endpoint's own scopes,
`jsonapi.atomic_operations.scopes`, and every operation in it is checked against the scopes
the equivalent request would need, before the transaction opens. The first operation that
fails refuses the whole batch with a `403 INSUFFICIENT_SCOPE` pointing at
`/atomic:operations/<index>`, and nothing is written. A create or update carrying
`relationships` needs only its own scopes.

## Responses and errors

A successful batch is always a `200` with `atomic:results` in operation order. An operation
with nothing to return, such as a `remove`, has an empty result object `{}`, so a batch of
only bodyless operations is still a `200`, never a `204`. A failing operation aborts the whole batch: the transaction rolls back
and the response is the failing operation's JSON:API error, rendered through the standard
[error pipeline](errors.md).

An error from `/operations` declares the extension on its `Content-Type`, as
`application/vnd.api+json; ext="https://jsonapi.org/ext/atomic"`, only when the request named
it, in the `Content-Type` `ext` or in the `ext` of any `Accept` range. The renderer reads the
headers itself, because some errors come before negotiation runs, such as a `405` for a method
other than `POST` or a `401` from the `auth` middleware. A request that never named the
extension gets a plain `application/vnd.api+json` error. A `415` or `406` never declares it,
because negotiation failed. Errors from a batch that got past negotiation, such as an empty or
malformed batch, an unknown query parameter, a pre-flight refusal or a rolled-back operation,
always declare it, since both headers named it. The Symfony bundle applies the same rule.

## In the OpenAPI document

The `/operations` endpoint is projected into the document with the atomic extension declared,
so a client generator knows the batch shape. See [openapi](openapi.md).
