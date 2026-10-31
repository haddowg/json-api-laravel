# Validation

Validation is **always on**. Unlike the Symfony bundle (where the validator bridge is an
opt-in `suggest`), `illuminate/validation` ships with Laravel, so this package translates
core's declared constraint vocabulary into **real Laravel rules with real, localizable
messages** on every write — no configuration (see
[ADR 0004](https://github.com/haddowg/json-api-laravel/blob/main/docs/adr/0004-write-authorization-is-pristine-subject-and-timed-before-hydration.md)
for the ordering). Core declares the constraints on the fields; this package executes them.

## How it works

A field's constraints (declared with the fluent methods or `constrain()`) become Laravel
rules; the document is validated **before hydration**, create-vs-update aware. A violation
renders as a `422` with a JSON:API `source.pointer` derived from the dot path:

```php
Str::make('title')->required()->maxLength(200);
Email::make('email')->required()->strict();
Str::make('password')->writeOnly()->minLength(8)->requiredOnCreate();
```

```json
{ "errors": [ {
  "status": "422",
  "source": { "pointer": "/data/attributes/title" },
  "detail": "The title field is required."
} ] }
```

Messages come from Laravel's translator (the `validation` language files), so they localise
for free and you override them the usual Laravel way.

## The constraint → rule map

Core's constraint VOs translate to the natural Laravel rule:

| Core constraint | Laravel rule |
| --- | --- |
| `required()` / `requiredOnCreate()` | `required` (create/update-context aware) |
| `nullable()` | `nullable` |
| `minLength()` / `maxLength()` | `min:` / `max:` (string length) |
| `min()` / `max()` (numeric) | `min:` / `max:` |
| `Pattern` | `regex:` |
| `In` | `Rule::in(...)` |
| `enum(BackedEnum::class)` | `Rule::enum(...)` |
| `After` / `Before` / date bounds | closure rules coercing to `DateTimeImmutable` |
| `Sequentially` | `bail` (stop on first failure) |
| `When` | `Validator::sometimes(...)` (conditional re-validation) |
| `CompareField` | `gte:`/`lte:` or closures against the sibling value |
| `AtLeastOneOf` | a composite closure |
| `Each` (list items) | array-element rules |
| `Map` children | nested dot-notation rules → `/data/attributes/<map>/<child>` pointers |
| `Obj` children | the same dot-notation cascade as `Map` (one value, one json column) |
| `OneOf` variants | the discriminator selects the variant; its children cascade like `Map`'s, an unknown discriminator is a `422` at `/data/attributes/<field>/<discriminator>` |
| `Shape` | not translated — value-validated by core's opis `SchemaValueValidator` when opis is installed (see [composite attributes](composite-attributes.md)) |

A required field must be present on create and may be left out of an update. When it is
sent, Laravel's `required` applies: a string can't be empty or whitespace-only, a list or
map can't be empty, and a boolean or number only has to be non-null, so `false` and `0`
are valid. A field that is both required and nullable also accepts an explicit `null`, on
create and on update, and still rejects a blank value.

### Empty and whitespace-only strings

Laravel skips every rule except `required` and its relatives for an empty or
whitespace-only string. The package adds a check to each constraint that can reject one, so
those strings get the same outcome as in the Symfony bundle, wherever the constraint is
declared: on the attribute, on an `Each` item, inside `Sequentially`, `AtLeastOneOf` or
`when()`, or on a `Map`, `Obj` or `OneOf` child. The string isn't trimmed first.

- `minLength(1)` rejects `""` but accepts `"   "`, which is three characters long.
  `maxLength` measures the same way.
- A pattern or format (`email`, `url`, `uuid`, `ulid`, `ip`, `slug`) accepts `""` and
  checks `"   "` like any other value, so `"   "` fails all of them.
- `in` and `notIn` check whether `""` or `"   "` is in the list.
- `min`, `exclusiveMin` and `multipleOf` reject a blank string as not numeric, and the
  item and property counts reject it as not an array. `max` and `exclusiveMax` accept it,
  as they do in the bundle.

An `Each` item that fails points at the item itself, for example `/data/attributes/tags/0`.
Rules you pass through `LaravelRules` or a custom translator keep Laravel's own handling.

The example's `users.passwordConfirm` composes three of these (an `AtLeastOneOf`, a
conditional `When`, and an equality `CompareField`).

## Unique values: `Rule::unique` pre-hydration

Core's `UniqueEntity` maps to Laravel's `Rule::unique` and runs **before hydration** — a
better fit than the bundle's post-hydration seam, because the DB-level rule joins the same
validation pass:

```php
use haddowg\JsonApiLaravel\Validation\Constraint\UniqueEntity;

Email::make('email')->required()->strict()->constrain(new UniqueEntity(['email']));
```

A duplicate is a `422` at `/data/attributes/email`, caught against the table before any write.
That check covers the whole table: a row the caller can't see still counts.

### Unique among the rows the caller can see

`scoped: true` compares only against the rows the caller can read, so one tenant's slug
doesn't block another's:

```php
Str::make('slug')->constrain(new UniqueEntity(['slug'], scoped: true));
```

The rows that count are the ones the type's own endpoint would return: the model's global
scopes apply, and a type served by a
[provider that scopes its rows](custom-data-providers.md#a-provider-that-scopes-its-rows)
counts only the rows that provider returns. A type with no Eloquent model is checked by
reading its provider. On update the row being updated never counts, so a `PATCH` that leaves
the value alone passes.

More options, each mirroring the Symfony bundle's `UniqueEntity`:

| Option | Effect |
| --- | --- |
| `using` | narrows the query: a local scope name such as `'active'`, or `fn (Builder $query) => …` |
| `errorPath` | the attribute the `422` points at, such as the second field of a composite key |
| `ignoreNull` | `true` by default: a null value skips the check; `false` treats null as a value |
| `model` | compares against another model's table |

```php
Str::make('code')->constrain(new UniqueEntity(['code', 'region'], using: 'active', errorPath: 'region', ignoreNull: false));
```

Setting any of these runs the check against the stored rows instead of `Rule::unique`.

## Entity-level (post-hydration) validation {#entity-level-validation}

For a check that needs the assembled entity (or that must run after hydration), the
post-hydration seam still exists — the Laravel twin of the bundle's `EntityConstraintInterface`.
Use it for domain invariants that a field-level rule cannot express.

## Filter-value validation

Filter values are validated too: a `filter[releasedAt][min]=banana` is a clean `400`, not a
database error. Declared filters carry their expected type (`->integer()`, `->boolean()`,
date-aware filters), and a value that fails coercion is rejected before it reaches the query.

## Custom constraints

Register a class-keyed translator for your own `ConstraintInterface` value objects — the
extension point. It is consulted after the built-in vocabulary, first
`supports()` match wins:

```php
use haddowg\JsonApiLaravel\Facades\JsonApi;

JsonApi::constraintTranslator(\App\JsonApi\Validation\MyConstraintTranslator::class);
```

When a constraint has no natural Laravel rule, ship it as an invokable
`ValidationRule` and translate to that.

### Native Laravel rules without a translator: `LaravelRules`

For a one-off Laravel-native rule, skip the bespoke VO + translator and wrap the raw
`illuminate/validation` rules in
[`LaravelRules`](../src/Validation/Constraint/LaravelRules.php), attached with
`constrain()`:

```php
Str::make('slug')->constrain(LaravelRules::make(['alpha_dash', 'min:3']));
```

The translator recognises the carrier and passes the wrapped rules straight to the
validator — so they run in the same `422` pass (and, because the filter-value validator
shares the translator, on `filter[…]` values too) with nothing to register. A rule can be
a string (`'min:3'`), a `Rule` builder (`Password::defaults()`), or an invokable
`ValidationRule`. Scope it with `->onCreate()` / `->onUpdate()`.

A native rule is **invisible to the generated OpenAPI/JSON Schema** by default (it
validates but doesn't document). Declare the value schema it implies with `->schema()` — a
closure over core's neutral `Schema` VO (it rides core's
[`ProvidesJsonSchema`](https://github.com/haddowg/json-api/blob/main/src/Resource/Constraint/ProvidesJsonSchema.php)
seam) — when you want it in the document:

```php
Str::make('slug')->constrain(
    LaravelRules::make(['alpha_dash', 'min:3'])
        ->schema(static fn(Schema $s): Schema => $s->withMinLength(3)),
);
```

Keep the fragment framework-neutral so the byte-compatible twin (the Symfony
`NativeConstraints` carrier) emits the identical schema. `LaravelRules` couples the field
to Laravel, so prefer a core constraint when one exists and reach here only for a genuinely
native rule.

## Non-goal: FormRequest integration

This package deliberately does **not** integrate Laravel `FormRequest` classes — they are a
competing validation source that would fight the document-first bridge. Declare your rules on
the resource fields (and the post-hydration seam); that is the single source of truth. The
optional opis JSON-Schema linter (`opis/json-schema`) is a separate, structural document check
— see [multi-server-and-testing](multi-server-and-testing.md#schema-conformance).
