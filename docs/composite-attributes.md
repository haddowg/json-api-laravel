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
`/data/attributes/format/medium`. An undiscriminated `OneOf` (no `discriminator()`) selects
its variant by the required member only that variant declares; a value carrying no
variant's signature, or two, is a `422` at the field itself, and since every write
replaces the whole value, a `PATCH` must carry every member the variant requires. The cascade goes one level deep: a child that is itself an
`Obj` or `OneOf` is not descended into. A child that is a list of structured elements is the
exception, covered next.

**A `Shape` is value-validated by core's `SchemaValueValidator`** (opis), not by
rule translation — its members are raw JSON Schema no illuminate rule can express.
The package wires the validator into `ResourceValidator` automatically when
`opis/json-schema` is installed, and each violation's pointer extends the field's
own: a missing member of the matched variant surfaces under
`/data/attributes/availability/...`. Without opis a `Shape` still **documents** (its
combinator projects into the OpenAPI schema); it just doesn't validate — install
`opis/json-schema` to get the `422`s.

## Lists of structured elements

An `ArrayList` whose `of()` is an `Obj` or `OneOf` holds a list of those shapes, as core's
[field types](https://github.com/haddowg/json-api/blob/main/docs/field-types.md#lists-of-structured-elements)
describe. A list of edits, each one a bare `trim` or a `find_replace` carrying its parameters:

```php
ArrayList::make('steps')
    ->of(OneOf::make('step')
        ->discriminator('operation')
        ->variant('trim')
        ->variant('find_replace', Obj::make('params')->required()->fields(
            Str::make('find')->required(),
            Str::make('replace')->required(),
        )))
    ->maxItems(50);
```

The bridge validates every element the client sends at its index. An `Obj` element runs its
children's rules, and a `OneOf` element the rules of the variant its discriminator names, so a
missing `find` in the third step is a `422` at `/data/attributes/steps/2/params/find`. An
unknown or missing `operation` is reported at `/data/attributes/steps/2/operation`, and an
element that is a scalar or `null` at `/data/attributes/steps/2`. Inside an element the
cascade follows every structured child, so a list nested in an element validates at both
indexes. The list's own `minItems`, `maxItems` and `uniqueItems` still apply to the list.

A write replaces the whole list and builds each element from nothing, so a `required()`
child must be present in every element on a `PATCH` too, not only on a create. A `PATCH`
that leaves the list out keeps it without validating its stored elements again. A JSON object
sent for the list, or a list sent where an element belongs, is refused by core with its own
`422` at the list or the element.

Store the list in one `json` column with an `array` cast, like any other composite.

## Open objects

An `Obj` declaring `additionalProperties()` also takes members it does not declare, each one
checked by that member field, as core's
[field types](https://github.com/haddowg/json-api/blob/main/docs/field-types.md#open-members)
describe. An inline spreadsheet import keyed by the user's column headers:

```php
ArrayList::make('rows')
    ->of(Obj::make('row')
        ->fields(Str::make('Key')->required())
        ->additionalProperties(Str::make('*')->maxLength(200))
        ->maxProperties(50))
    ->maxItems(1000);
```

The validator checks each undeclared member against the member field's rules, with its
presence and nullability, at the member's own pointer. The key is escaped as a JSON Pointer
token, so a too-long value under the `Unit/Price` header of the fourth row is a `422` at
`/data/attributes/rows/3/Unit~1Price`, and one under `a~b` at `.../a~0b`. Each member is
validated on its own under a placeholder key and its errors moved under the real pointer,
because Laravel's dot notation would misread a header holding `.` or `*`: `Unit.Cost` reports
at `/data/attributes/rows/3/Unit.Cost`. `minProperties()` and `maxProperties()` count every
member the object carries and report at the object. A member field that is itself structured
validates its children under the member's pointer.

This holds wherever the object sits: an attribute, a child of an `Obj`, `Map` or `OneOf`
variant at any depth, a list element, or a member of another open object. Core's
`ObjectValues` walk finds each one. Only what the client sends is checked, so a `PATCH` that
merges members onto a stored object counts the members it sends. A member field read-only
for the caller leaves its members unchecked, since core's hydration ignores them, and a
closed object still refuses an undeclared member with a `400` `ATTRIBUTE_MEMBER_UNRECOGNIZED`.

The undeclared members are stored beside the declared children in the object's `json`
column.

## Storage: one `json` column

A composite attribute is **one value** — the natural Eloquent mapping is a single
`json` column with an `array` cast:

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
explicit `null` clears it.

A `DateTime`, `Date` or `Time` child needs nothing extra. Core hydrates a nested date to a
string, so the `array` cast stores plain JSON. A `DateTime` child stores its instant in UTC
as `Y-m-d\TH:i:s.uP`, and a `Date` or `Time` child stores its value in the field's own
format. On a read a stored `DateTime` moves into the output timezone and renders in the
field's format, and a `Date` or `Time` renders as stored. Code that reads a nested date off
the model gets that string, not a `\DateTimeImmutable`. Core's
[nested dates](https://github.com/haddowg/json-api/blob/main/docs/field-types.md#nested-dates-are-stored-as-strings)
section has the exact rules. A child that needs a richer PHP type than JSON can hold,
such as a value object, still goes through the field-level
`serializeUsing()`/`fillUsing()` hooks rather than a custom cast, the same pattern the
workbench album's `releaseInfo` map uses.

A `serializeUsing()` or `extractUsing()` hook on an `Obj`, `Map` or `OneOf` changes its
read schema: the OpenAPI document keeps the children's properties but requires none of
them, and a `OneOf` no longer requires its discriminator. An `extractUsing()` value still
renders through the children. A `serializeUsing()` value skips them and goes out as the
hook returns it, so keep it to the declared shape or the response won't match the
document.

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
