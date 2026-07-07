# Asynchronous writes (`202 Accepted` / `303 See Other`)

JSON:API 1.1 describes an [asynchronous-processing](https://jsonapi.org/recommendations/#asynchronous-processing)
lifecycle: a server that cannot finish a write within the request accepts it with a
`202 Accepted`, points the client at a **job resource** to poll (a `Content-Location`
header, optionally a `Retry-After` hint), and — once the work completes — answers a
`GET` on that job resource with `303 See Other`, redirecting to the resource the
operation produced.

The package exposes this as a **thin seam**: your persister decides to go async and
returns a marker; the handler renders the spec-correct `202`. *How* you queue the work
is your choice — the recipe below dispatches a [Laravel queued job](https://laravel.com/docs/queues),
but nothing about the queue is baked in.

The whole lifecycle is **reflected in the generated
[OpenAPI document](openapi.md#per-operation-response-declarations)**: declare the write's `202`
and the job's completion `303` with [per-operation response
declarations](resources.md#declaring-response-shapes), and a codegen client sees the async
contract — the `202` + job resource, the `Retry-After` hint, and the `303` to the produced
resource — from the document alone.

## Accepting the write — `AcceptedForProcessing`

A [`DataPersister`](custom-data-providers.md) that dispatches a write instead of committing
it returns an `AcceptedForProcessing` from `create()` (or `update()`) in place of the
persisted entity. The [`CrudOperationHandler`](lifecycle.md) renders it as a `202` rather
than the usual `201`/`200`:

```php
use haddowg\JsonApiLaravel\DataPersister\AcceptedForProcessing;
use haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface;
use Workbench\App\Domain\Album;

final class AsyncAlbumPersister implements DataPersisterInterface
{
    public function supports(string $type): bool
    {
        return $type === 'albums';
    }

    public function instantiate(string $type): object
    {
        return new Album();
    }

    public function create(string $type, object $entity): object
    {
        // Hand the work off to the queue instead of persisting inline. ProcessAlbum
        // is an ordinary queued job (implements ShouldQueue); it persists the album
        // and updates the tracked Job row when it runs.
        $jobId = (string) Str::uuid();
        ProcessAlbum::dispatch($jobId, $entity);

        // Point the client at a job resource it can poll for completion.
        return AcceptedForProcessing::poll(url("/api/jobs/{$jobId}"))
            ->withJob(new Job($jobId, 'queued'), 'jobs')
            ->withRetryAfter(30);
    }

    // update()/delete()/mutateRelationship() as usual…
}
```

The response is a `202`:

```http
HTTP/1.1 202 Accepted
Content-Type: application/vnd.api+json
Content-Location: https://example.test/api/jobs/9f3b
Retry-After: 30

{ "data": { "type": "jobs", "id": "9f3b", "attributes": { "status": "queued" } } }
```

- `AcceptedForProcessing::poll($url)` sets the `Content-Location` — the URL the client
  polls.
- `->withJob($job, 'jobs')` renders `$job` as the `202` body through the `jobs` type's
  registered serializer. Omit it (or use `->withMeta([...])`) for a meta-only status
  document.
- `->withRetryAfter(30)` sets `Retry-After` in delta-seconds; a `\DateTimeInterface`
  is emitted as an HTTP-date instead.

The `jobs` type is an ordinary JSON:API type — register a serializer for it (a
standalone `#[AsJsonApiSerializer(type: 'jobs')]`, or a full resource if you want its
own endpoints). Persist the job wherever your queue tracks state (a `jobs` table, the
cache) so the polling endpoint can report its progress.

Declare the async response on the resource so the `202` is advertised in OpenAPI — always-async
`create: [new Accepted('jobs')]`, or maybe-async `create: [new Created(), new Accepted('jobs')]`
(see [response shapes](resources.md#declaring-response-shapes)).

## Reporting completion — `303 See Other`

Point the client at the job resource: while the work runs, `GET`ting it returns the job's
status (`200`); once complete it answers `303 See Other`, redirecting to the produced
resource. The spec-canonical way to express that is the **job resource's own fetch-one** —
implement [`ResolvesCompletionRedirect`](https://github.com/haddowg/json-api/blob/main/src/Resource/ResolvesCompletionRedirect.php)
and declare `fetchOne: [new Ok(), new SeeOther()]`:

```php
use haddowg\JsonApi\OpenApi\Metadata\Ok;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\ResolvesCompletionRedirect;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(readOnly: true, fetchOne: [new Ok(), new SeeOther()])]
final class JobResource extends AbstractResource implements ResolvesCompletionRedirect
{
    public static string $type = 'jobs';

    public function fields(): array
    {
        return [Id::make(), Str::make('state')->readOnly()];
    }

    // Done → the produced resource's URL (a 303 Location); still running → null (a normal 200).
    public function completionLocation(object $entity): ?string
    {
        \assert($entity instanceof Job);

        return $entity->state === 'completed'
            ? url("/api/albums/{$entity->createdId}")
            : null;
    }
}
```

```http
GET /api/jobs/9f3b      → 200   { "data": { "type": "jobs", "attributes": { "state": "processing" } } }
GET /api/jobs/9f3b      → 303   Location: https://example.test/api/albums/42
```

The handler consults `completionLocation()` after loading the entity: a non-null string
renders a `303`, `null` renders the job normally. The `fetchOne: [new Ok(), new SeeOther()]`
declaration advertises both outcomes in the OpenAPI document.

### Completion via a custom action

When completion isn't a plain job fetch — a side-effecting `POST`, a collection-scoped
result, or a dedicated poll endpoint — model it as a [custom action](actions.md) declaring
`responds: [new Accepted('jobs'), new SeeOther()]`, returning `$context->seeOther($url)` (done)
or `$context->accepted($pollUrl)->withRetryAfter(30)` (still running):

```php
use haddowg\JsonApi\OpenApi\Metadata\Accepted;
use haddowg\JsonApi\OpenApi\Metadata\SeeOther;
use haddowg\JsonApi\Response\AcceptedResponse;
use haddowg\JsonApi\Response\SeeOtherResponse;
use haddowg\JsonApiLaravel\Action\ActionContext;
use haddowg\JsonApiLaravel\Action\ActionHandlerInterface;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiAction;

#[AsJsonApiAction(type: 'jobs', path: 'result', methods: ['GET'], responds: [new Accepted('jobs'), new SeeOther()])]
final class JobResult implements ActionHandlerInterface
{
    public function handle(ActionContext $context): AcceptedResponse|SeeOtherResponse
    {
        $job = $context->entity();
        \assert($job instanceof Job);

        return $job->state === 'completed'
            ? $context->seeOther(url("/api/albums/{$job->createdId}"))
            : $context->accepted(url("/api/jobs/{$job->id}/-actions/result"))->withRetryAfter(30);
    }
}
```

So a polling client sees `202` (with `Retry-After`) until the work finishes, then a single
`303` — and both are advertised in the generated document.

## Notes

- **Atomic Operations.** An async accept cannot join an
  [Atomic Operations](atomic-operations.md) batch — it defers the write past the
  batch's all-or-nothing commit — so a persister that returns `AcceptedForProcessing`
  inside a batch fails that sub-operation (`422`, code `ASYNC_WRITE_IN_ATOMIC_OPERATION`)
  and the batch rolls back. Keep async types out of atomic batches.
- **Scope.** The seam covers `create()` and `update()`. `delete()` returns `void`, so
  an async delete is not expressible through it today.
- **Queue workers.** The seam is the HTTP half only. Your queued job owns the actual
  write — dispatch it, then persist and flip the tracked `jobs` row to `done` when it runs,
  so the completion action can redirect. On a [long-running runtime](optimize.md) (Octane /
  a queue worker) the per-request container is reset between jobs as usual.
- **Portability.** `AcceptedResponse`/`SeeOtherResponse` are core, framework-neutral
  response value objects, so this package produces byte-identical `202`/`303` responses to
  the Symfony bundle over its own queue.

The seam is exercised end to end by the dual-provider `AsyncWriteConformanceTestCase`
(accepted create → `202` + job resource → `303` completion → atomic rejection), on both
the in-memory witness and the Eloquent reference layer.
