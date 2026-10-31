# A server's output timezone reaches pivot meta through the pivot field, and only when a zone applies

- **Status:** superseded in part by [ADR 0072](0072-pivot-dates-render-through-their-field.md): a pivot date renders through its field whether or not a zone applies.

Core converts `DateTime` attributes in `AbstractResource::getAttributes()`, but `meta.pivot`
never goes through there: the handler renders whatever value the pivot model hands back. So
the handler applies the zone itself. A pivot value that is a `\DateTimeInterface` renders
through its declared `DateTime` pivot field when a zone applies to that field: the field's own
`outputTimezone()`, else the server's. `Date` and `Time` pivot fields never pick up the
server's zone, the same as in core. Every other value, including every value when no zone
applies, renders as the pivot model returned it, so an app without `output_timezone` sees
the same bytes as before.

## Considered options

- **Always render pivot dates through the field**, as the Symfony bundle's `PivotFields::cast()`
  does. That fixes the pivot format even with no zone set, but it changes the output for every
  app whose pivot model casts a date column. That belongs in its own change, not in a
  timezone feature that promises no change while the option is unset.
- **Parse uncast pivot strings.** An uncast column comes back as the stored string, which
  has no zone. Guessing one from `app.timezone` or the connection would convert values the
  app never said were in that zone. A pivot model that casts the column gets the conversion.

## Consequences

- With a zone set, a cast pivot date switches from PHP's or Carbon's JSON form to the
  field's format (ATOM by default), so it matches the attribute format.
- The `PivotSubstitutingResolver` also implements core's `ProvidesOutputTimezoneInterface`
  and delegates it. Resources here get their resolver from core's registry, never from this
  decorator, so the delegation changes no output today. It keeps the decorator honest if that
  ever changes.
