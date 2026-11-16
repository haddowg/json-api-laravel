# A morph filter reads the model's morphTo on Eloquent and declared fields in memory

On Eloquent a `WhereMorph` over a `MorphTo` compares the type and id columns of the model's own `morphTo` relation, the type as the morph class of the model serving the requested type, so the morph map is honoured without a second declaration and a relation identified by an `identifiedBy()` closure still filters; core's `identifiedByColumns()` is the fallback for a model without one. The in-memory provider has no Eloquent relation to read, so it compares the fields `identifiedByColumns()` or `identifiedByRows()` name, and route registration and `jsonapi:optimize` refuse a `WhereMorph` its provider cannot compare.

## Considered Options

- Requiring `identifiedByColumns()` on Eloquent as the bundle does on Doctrine: it repeats what the `morphTo` and the morph map already say, and the two can drift.
- `whereHasMorph()`: it joins the target tables and needs the targets to exist, where the filter compares only the stored identifier.
