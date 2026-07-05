# The validation bridge cascades `Obj` and `OneOf` children

- **Status:** accepted

The always-on validation bridge (`ResourceValidator`) validates the children of the
two composite attribute types (core ADRs 0118/0119), surfacing per-child `422`s with
`/data/attributes/<field>/<child>` pointers — the Laravel twin of the Symfony
bundle's ADR 0111:

- **`Obj`** validates identically to `Map` — each present writable child registers
  under a dot-notation key (`address.city`) in the same rule map (one level deep,
  same create/update presence resolution). `Obj` simply joins `Map` in the existing
  `mapChildRules` cascade.
- **`OneOf`** validates **value-dependently**, as a document-level pass (like the
  cross-field `CompareField`s): the incoming discriminator selects the variant, whose
  children then register under the same dotted keys through a dedicated validator
  run. A static rule map cannot express this — which variant's rules apply depends on
  the value. An array whose discriminator names no variant is one `422` at
  `/data/attributes/<field>/<discriminator>`; a non-array value fails the field's own
  `array` rule.

**Why.** Core declares the composite children + their constraints but never executes
them; the host bridge owns validation. `Obj` is structurally `Map` (children in one
value), so it reuses the existing cascade verbatim. `OneOf`'s variant selection is
intrinsically value-dependent, so it cannot ride the static rule map and runs in the
same document-level phase that already handles value-dependent rules — keeping the
pointer semantics identical to every other attribute.

## Consequences

The cascade stays one level deep: a child that is itself an `Obj`/`OneOf` is not
descended into here. `OneOf` presence/nullability is still resolved by the main
per-field pass (a required union must be present); only its variant children move to
the value-dependent pass. Witnessed end-to-end over HTTP by
`CompositeConformanceTestCase` against both providers (valid create, `Obj` child
pointer, `OneOf` variant-child pointer, unknown discriminator). The `Shape`
constraint's combinator validation is ADR 0013.
