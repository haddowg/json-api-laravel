# Getting started

This page builds the `albums` type from nothing to a working, validated, related endpoint —
the same `AlbumResource` the [music-catalog workbench](workbench.md) ships, layered up one
capability at a time. It assumes you have [installed](install.md) the package. If you are new
to JSON:API itself, read core's
[getting-started](https://github.com/haddowg/json-api/blob/main/docs/getting-started.md)
first.

## 1. A model and a resource

Start with an Eloquent model — a plain model, nothing JSON:API-specific:

```php
// app/Models/Album.php
final class Album extends Model
{
    protected $casts = ['released_at' => 'datetime', 'explicit' => 'boolean'];
}
```

Then a resource. It extends core's `AbstractResource`, declares its wire `$type`, and lists
its `fields()`:

```php
<?php

declare(strict_types=1);

namespace App\JsonApi;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Str;

final class AlbumResource extends AbstractResource
{
    public static string $type = 'albums';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title')->required()->maxLength(200)->sortable(),
        ];
    }
}
```

Drop it in `app/JsonApi/`. That is the whole integration: you now have all five CRUD
endpoints under the `default` server's `/api` prefix.

```
GET    /api/albums
GET    /api/albums/{id}
POST   /api/albums
PATCH  /api/albums/{id}
DELETE /api/albums/{id}
```

The package maps the `albums` type to your `Album` model by convention — the kebab/plural
type, singularized and studly-cased under the `App\Models` namespace (configurable as
`jsonapi.eloquent.model_namespace`) — and auto-registers the reference
[Eloquent data layer](eloquent.md) for every type it can map, so reads and writes work with
no provider wired by hand. When the names diverge, declare the model on the attribute —
`#[AsJsonApiResource(model: Album::class)]` — and for full control register the
provider/persister pair explicitly, which always wins
([eloquent](eloquent.md#the-model-map-three-tiers)).

```bash
curl -H 'Accept: application/vnd.api+json' http://localhost/api/albums
```

```json
{
  "jsonapi": { "version": "1.1" },
  "data": [
    { "type": "albums", "id": "1",
      "attributes": { "title": "OK Computer" },
      "links": { "self": "http://localhost/api/albums/1" } }
  ]
}
```

## 2. More fields, sorting and filtering

Core's [field DSL](https://github.com/haddowg/json-api/blob/main/docs/fields.md) covers the
JSON:API vocabulary. `storedAs()` maps a wire name to a differently-named column;
`sortable()` opts a field into `?sort`; `filters()` declares the accepted `?filter` keys:

```php
use haddowg\JsonApi\Resource\Field\Boolean;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Decimal;
use haddowg\JsonApi\Resource\Filter\Contains;
use haddowg\JsonApi\Resource\Filter\DateRange;

public function fields(): array
{
    return [
        Id::make(),
        Str::make('title')->required()->maxLength(200)->sortable(),
        Decimal::make('averageRating')->storedAs('average_rating')->readOnly()->nullable(),
        DateTime::make('releasedAt')->storedAs('released_at')->sortable(),
        Boolean::make('explicit'),
    ];
}

public function filters(): array
{
    return [
        Contains::make('title'),                       // filter[title]=comp
        DateRange::make('releasedAt', 'released_at'),  // filter[releasedAt][min]=…&filter[releasedAt][max]=…
    ];
}
```

```
GET /api/albums?sort=-releasedAt&filter[title]=comp&page[size]=10
```

An unknown `?sort`/`?filter` key is a `400` by default (strict query parameters — see
[configuration](configuration.md)). Sorting, filtering, sparse fieldsets and pagination all
work with no further code — the [Eloquent](eloquent.md) provider translates them to the
query builder. Pagination strategies (page / offset / cursor) are on
[pagination](pagination.md).

## 3. A default sort and a validated write

Give the collection a stable order when the client sends no `?sort`, and let core's declared
constraints validate writes:

```php
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;

public function defaultSort(): array
{
    return [new SortDirective(SortByField::make('releasedAt', 'released_at'), descending: true)];
}
```

`Str::make('title')->required()->maxLength(200)` already means a `POST` with a missing or
over-long title is a `422`:

```json
{ "errors": [ {
  "status": "422",
  "source": { "pointer": "/data/attributes/title" },
  "detail": "The title field is required."
} ] }
```

The rules are real `illuminate/validation` rules with real (localizable) messages — see
[validation](validation.md).

## 4. A relationship

Declare a relation and you get its linkage, its `self`/`related` links, the related and
relationship endpoints, and `?include` — all by convention. The relation name maps to an
Eloquent relationship method on the model:

```php
use haddowg\JsonApi\Resource\Field\BelongsTo;
use haddowg\JsonApi\Resource\Field\HasMany;

public function fields(): array
{
    return [
        Id::make(),
        Str::make('title')->required()->maxLength(200)->sortable(),
        BelongsTo::make('artist', 'artists'),
        HasMany::make('tracks', 'tracks')->countable(),
    ];
}
```

```
GET /api/albums/1?include=artist,tracks
GET /api/albums/1/tracks           # the related endpoint
GET /api/albums/1/relationships/tracks
```

Relationships — reads, mutations, pivots, `?withCount`, and the Relationship Queries profile
— are covered in full on [relationships](relationships.md).

## 5. Where to go next

You have a real endpoint. The rest of the docs read in arcs:

1. **Wiring** — [resources](resources.md), [capability-composition](capability-composition.md),
   [configuration](configuration.md), [routing](routing.md).
2. **The data layer** — [eloquent](eloquent.md), [custom-data-providers](custom-data-providers.md),
   [pagination](pagination.md).
3. **Writing** — [validation](validation.md), [authorization](authorization.md),
   [lifecycle](lifecycle.md) / [lifecycle-hooks](lifecycle-hooks.md),
   [actions](actions.md), [atomic-operations](atomic-operations.md).
4. **Operations** — [errors](errors.md), [multi-server-and-testing](multi-server-and-testing.md),
   [openapi](openapi.md), [optimize](optimize.md).
