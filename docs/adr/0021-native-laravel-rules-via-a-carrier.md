# Native Laravel validation rules attach via a `LaravelRules` carrier

- **Status:** accepted

A field or filter can wrap one or more native `illuminate/validation` rules — a rule
string (`'min:3'`), a `Rule` builder, an invokable `ValidationRule` — in a `LaravelRules`
value object and attach them with core's `constrain()`. The `ConstraintTranslator`
recognises the carrier and passes the wrapped rules straight to Laravel's validator
(running in the same `422`-with-`source.pointer` pass, and — since the filter-value
validator shares the translator — on `filter[…]` values too).

**Why.** The existing extension point, a class-keyed `ConstraintTranslatorInterface`,
needs a bespoke `ConstraintInterface` value object **and** a registered translator per
rule — the right shape for a reusable, portable constraint, but heavy for a one-off
Laravel-native check (`Password::defaults()`, an app's own invokable rule). `LaravelRules`
is the zero-registration escape hatch: the rule is already a Laravel rule, so there is
nothing to translate. It is the canonical first-party instance of the `constrain()` seam,
and the twin of the Symfony bundle's `NativeConstraints`.

**Schema is opt-in, and neutral.** `LaravelRules` implements core's `ProvidesJsonSchema`
(core's self-describing-constraint seam), so it is invisible to the generated OpenAPI /
JSON Schema by default and documents only when the author declares the value schema the
rule implies via `->schema(fn (Schema $s) => …)` — a closure over core's framework-neutral
`Schema` VO, so the byte-compatible twin (the Symfony `NativeConstraints` carrier) emits
the identical fragment.

## Consequences

A `LaravelRules` couples the field to Laravel (it is not portable to another framework
integration), so the guidance is: prefer a core constraint when one exists (portable +
documented), and reach here only for a genuinely Laravel-native rule. The translator gains
one arm before its `translateExtension` fallback; no new binding or tag.
