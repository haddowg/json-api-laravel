# Composite attributes

The core composite attribute types — [`Obj`, `OneOf`](https://github.com/haddowg/json-api/blob/main/docs/field-types.md#obj)
and the [`Shape` constraint](https://github.com/haddowg/json-api/blob/main/docs/constraints.md#shape-composite-schema-constraints)
— let a resource expose a structured attribute stored as **one value**: a typed
nested object, a discriminated union, or a free-form map whose overall shape is
asserted by a composite JSON Schema. Core defines the types and their semantics;
this page covers what the package adds — how each validates, and how the values
persist through Eloquent.

The three kinds at a glance:

```php
// A typed nested object in one value — children are declared fields.
Obj::make('packaging')->nullable()->fields(
    Str::make('material')->required()->maxLength(40),
    Boolean::make('gatefold'),
),

// A discriminated union — `medium` selects which variant's children apply.
OneOf::make('format')->nullable()->discriminator('medium')
    ->variant('vinyl', Integer::make('rpm')->required()->min(16)->max(78), Boolean::make('coloured'))
    ->variant('cd', Integer::make('discs')->required()->min(1))
    ->variant('digital', Str::make('codec')->required()->maxLength(16), Integer::make('bitrateKbps')->min(32)),

// A free-form map, its shape asserted by raw member schemas.
ArrayHash::make('availability')->nullable()->constrain(
    Shape::anyOf($worldwideShape, $regionListShape),
),
```

## How each kind validates

The split follows the constructive/assertional divide:

**`Obj` and `OneOf` children run through the [always-on bridge](validation.md)** —
the same dot-notation cascade as a `Map`'s children. Each child's constraint
vocabulary translates to native illuminate rules, and a violation points at the
child: `/data/attributes/packaging/material`, `/data/attributes/format/rpm`. For a
`OneOf`, only the **selected variant's** children are validated, and an unknown or
missing discriminator is itself the violation — a `422` pointing at
`/data/attributes/format/medium`.

**A `Shape` is value-validated by core's `SchemaValueValidator`** (opis), not by
rule translation — its members are raw JSON Schema no illuminate rule can express.
The package wires the validator into `ResourceValidator` automatically when
`opis/json-schema` is installed, and each violation's pointer extends the field's
own: a missing member of the matched variant surfaces under
`/data/attributes/availability/...`. Without opis a `Shape` still **documents** (its
combinator projects into the OpenAPI schema); it just doesn't validate — install
`opis/json-schema` to get the `422`s.

Design records: [ADR 0012](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0012-composite-attribute-validation-cascade.md)
(the `Obj`/`OneOf` cascade) and [ADR 0013](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0013-shape-value-validation-via-core-schema-value-validator.md)
(`Shape` value validation through the core validator).

## Storage: one `json` column

A composite attribute is **one value** — the natural Eloquent mapping is a single
`json` column with an `array` cast and scalar children:

```php
final class Release extends Model
{
    protected $casts = [
        'format' => 'array',
        'packaging' => 'array',
        'availability' => 'array',
        'dimensions' => 'array',
    ];
}
```

No custom cast is involved: the whole object round-trips as one JSON document, an
`Obj`'s partial `PATCH` merges per-child before the column is written, and an
explicit `null` clears it. A child value that needs a richer PHP type than JSON
scalars (a `DateTime` inside the object, a value object) rides the field-level
`serializeUsing()`/`fillUsing()` escape hatch rather than a custom cast — the same
pattern the workbench album's `releaseInfo` map uses.

## Worked example

The workbench's `releases` resource
([`ReleaseResource`](https://github.com/haddowg/json-api-laravel/blob/main/workbench/app/MusicCatalog/JsonApi/ReleaseResource.php)
over the `mc_releases` table) showcases all three kinds on one type — a `OneOf`
format discriminated by `medium` (vinyl / cd / digital), an `Obj` packaging, and
`Shape`'d availability/dimensions maps — each a single `json` column, and each
keyword (`oneOf`, `anyOf`, `allOf`, `discriminator`) visible in the exported OpenAPI
document (byte-compatible with the Symfony bundle example's twin). The dual-provider
conformance witness is
[`CompositeConformanceTestCase`](https://github.com/haddowg/json-api-laravel/blob/main/tests/Conformance/CompositeConformanceTestCase.php),
which runs the same validation-pointer and json-column round-trip assertions
against the in-memory and Eloquent providers.

## Next

- [Validation](validation.md) — the bridge, the `422` shape, and the translation map.
- Core [field types](https://github.com/haddowg/json-api/blob/main/docs/field-types.md#obj)
  — `Obj`/`OneOf` semantics (merge, discriminator fallback, OpenAPI projection).
- Core [constraints](https://github.com/haddowg/json-api/blob/main/docs/constraints.md#shape-composite-schema-constraints)
  — the `Shape` builders and the `SchemaValueValidator` execution route.
