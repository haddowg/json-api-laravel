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

## Responses and errors

A successful batch returns `atomic:results` in operation order (a `200`, or a `204` when every
operation is bodyless). A failing operation aborts the whole batch: the transaction rolls back
and the response is the failing operation's JSON:API error, rendered through the standard
[error pipeline](errors.md).

## In the OpenAPI document

The `/operations` endpoint is projected into the document with the atomic extension declared,
so a client generator knows the batch shape. See [openapi](openapi.md).
