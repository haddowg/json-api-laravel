# Custom serializers and hydrators

`AbstractResource` supplies both the read shape (it *is* the serializer) and the write shape
(it *is* the hydrator), driven by the `fields()` DSL. Most customisation happens on the
fields; when you need more, you drop to a hand-written serializer or hydrator — standalone
for a resource-less type, or as a per-resource override. This page covers all three.

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

A resource can keep its field inventory and hand *one* concern to a dedicated class:
`#[AsJsonApiResource(serializer: …)]` renders reads through a custom `SerializerInterface`
while writes stay field-driven, and `#[AsJsonApiResource(hydrator: …)]` hydrates writes
through a custom `HydratorInterface` while reads stay field-driven (declare both to override
both). This is the escape hatch for a wire shape the field DSL cannot express — a
request-aware attribute, a `meta` member surfaced from an injected dependency, or a write
that fans one member out to several columns:

```php
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

#[AsJsonApiResource(serializer: TrackSerializer::class)]
final class TrackResource extends AbstractResource { /* fields still hydrate writes */ }

#[AsJsonApiResource(hydrator: PlaylistHydrator::class)]
final class PlaylistResource extends AbstractResource { /* fields still render reads */ }
```

The override class is container-constructed on first use, so it can inject dependencies;
bind scalar constructor arguments with `$app->when(...)->needs('$arg')->give(...)` — see
[capability-composition](capability-composition.md#dependency-injection). A serializer
override that needs to render the resource's relationships opts into core's
`SerializerResolverAwareInterface`; the registry injects the resolver after construction.
An override naming a class that does not implement its core contract fails discovery with a
`LogicException`. Extending core's `AbstractSerializer` / `AbstractHydrator` is the practical
starting point — the hydrator base supplies the create/update dispatch and the
client-generated-id policy, leaving you the attribute fan-out.

Reach for the override only when the cheaper seams fall short: **per-field
`serializeUsing`/`extractUsing`/`fillUsing`** closures (above) cover most wire-shape changes
in one declaration, a **standalone `#[AsJsonApiSerializer]`** serves a type with no resource
at all, and the **[hook trait](lifecycle-hooks.md)** handles write-side derivation that
belongs after hydration.

## Overriding the operation handler

The single generic operation handler is resolved from the container and can be replaced by
decoration for a bespoke operation flow — the rare case the field closures and hooks cannot
reach. Bind your own implementation of the handler contract; it delegates the operations you
do not override. Most needs are better served by a [hook](lifecycle-hooks.md) or a
[custom provider/persister](custom-data-providers.md).
