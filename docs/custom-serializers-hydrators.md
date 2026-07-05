# Custom serializers and hydrators

`AbstractResource` supplies both the read shape (it *is* the serializer) and the write shape
(it *is* the hydrator), driven by the `fields()` DSL. Most customisation happens on the
fields; when you need more, you drop to a hand-written serializer. This page covers both, and
is honest about one capability still on the roadmap.

## Shaping reads and writes on the fields

The common case needs no separate class — per-field closures cover it. Each closure receives
the model (and, for writes, the submitted value / whole payload):

```php
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Str;

// Read: derive a value at serialize time.
Str::make('displayTitle')->computed()->readOnly()
    ->extractUsing(static fn(mixed $t): string =>
        Accessor::get($t, 'track_number') . '. ' . Accessor::get($t, 'title'));

// Read + write: a JSON Map with a custom round-trip.
Map::make('releaseInfo')->nullable()->fields(
        Str::make('label'),
        Str::make('catalogueNumber')->readOnly(),
    )
    ->serializeUsing(static fn(mixed $m) => Accessor::get($m, 'release_info') ?: null)
    ->fillUsing(static function (mixed $m, mixed $v) {
        Accessor::set($m, 'release_info', \is_array($v) ? $v : null);
        return $m;
    });
```

`serializeUsing`/`extractUsing` shape output; `fillUsing`/`deserializeUsing` shape input.
Only filled members are hydrated on a `PATCH`, so partial updates are correct by default —
the model owns its defaults.

## A standalone serializer

For a type with no `AbstractResource` — a fully hand-written wire shape, or a resource-less
reference type — implement core's `SerializerInterface` and register it with
`#[AsJsonApiSerializer]`:

```php
use haddowg\JsonApiLaravel\Attribute\AsJsonApiSerializer;
use haddowg\JsonApiLaravel\Operation\Operation;
use haddowg\JsonApi\Serializer\SerializerInterface;

#[AsJsonApiSerializer(type: 'countries', operations: [Operation::FetchCollection, Operation::FetchOne])]
final class CountrySerializer implements SerializerInterface { /* … */ }
```

The class is container-constructed, so it can inject dependencies (a repository, a config
value). Bind scalar constructor arguments with `$app->when(...)->needs('$arg')->give(...)` —
see [capability-composition](capability-composition.md#dependency-injection). Pair it with a
[custom provider](custom-data-providers.md) to make the type fetchable — the example's
`charts`/`countries` do exactly this.

## Writing: hydration and post-hydration seams

Whole-document hydration is core's, driven by the resource. Two Laravel seams let you shape a
write beyond the field closures:

- **Lifecycle hooks** — a `beforeCreate`/`beforeUpdate` hook mutates the entity before the
  persister flushes (the example derives a `playlists` slug and stamps an `externalId` this
  way). See [lifecycle-hooks](lifecycle-hooks.md).
- **Entity-level validation** — a post-hydration constraint that sees the assembled entity
  (e.g. cross-field checks). See [validation](validation.md#entity-level-validation).

```php
public function beforeCreate(object $entity, HookContext $context): void
{
    $title = Accessor::get($entity, 'title');
    Accessor::set($entity, 'slug', \Str::slug(\is_string($title) ? $title : ''));
}
```

## Overriding a resource's serializer or hydrator with a bound class

> [!NOTE]
> The Symfony bundle lets `#[AsJsonApiResource(serializer: …, hydrator: …)]` point a resource
> at a hand-written serializer/hydrator class (e.g. to inject a constructor argument surfaced
> in `meta`). **That per-resource override is not yet carried on this package's
> `#[AsJsonApiResource]` attribute.** Today, achieve the same outcomes with:
>
> - **per-field `serializeUsing`/`extractUsing`/`fillUsing`** closures (above) for wire-shape
>   changes — this covers the overwhelming majority of cases and keeps one declaration;
> - **a standalone `#[AsJsonApiSerializer]`** for a type whose read shape is fully
>   hand-written and container-injected;
> - **the [hook trait](lifecycle-hooks.md)** for write-side derivation that the bundle's
>   hydrator override would have done.
>
> The example's `tracks` (a bundle serializer override) and `playlists` (a bundle hydrator
> override) use these seams instead; the projected wire shape and OpenAPI document are
> identical. Carrying the attribute-level override is tracked in the
> [parity audit](https://github.com/haddowg/json-api-laravel/blob/main/docs/parity-audit.md).

## Overriding the operation handler

The single generic operation handler is resolved from the container and can be replaced by
decoration for a bespoke operation flow — the rare case the field closures and hooks cannot
reach. Bind your own implementation of the handler contract; it delegates the operations you
do not override. Most needs are better served by a [hook](lifecycle-hooks.md) or a
[custom provider/persister](custom-data-providers.md).
