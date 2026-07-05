# Validation

Validation is **always on**. Unlike the Symfony bundle (where the validator bridge is an
opt-in `suggest`), `illuminate/validation` ships with Laravel, so this package translates
core's declared constraint vocabulary into **real Laravel rules with real, localizable
messages** on every write — no configuration (PLAN decision 6,
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
extension point (PLAN decision 6). It is consulted after the built-in vocabulary, first
`supports()` match wins:

```php
use haddowg\JsonApiLaravel\Facades\JsonApi;

JsonApi::constraintTranslator(\App\JsonApi\Validation\MyConstraintTranslator::class);
```

When a constraint has no natural Laravel rule, ship it as an invokable
`ValidationRule` and translate to that.

## Non-goal: FormRequest integration

This package deliberately does **not** integrate Laravel `FormRequest` classes — they are a
competing validation source that would fight the document-first bridge. Declare your rules on
the resource fields (and the post-hydration seam); that is the single source of truth. The
optional opis JSON-Schema linter (`opis/json-schema`) is a separate, structural document check
— see [multi-server-and-testing](multi-server-and-testing.md#schema-conformance).
