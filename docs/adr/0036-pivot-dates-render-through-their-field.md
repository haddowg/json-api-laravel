# Pivot dates render through their field, cast when the pivot model doesn't cast them

ADR 0030 left a pivot date as the pivot model returned it unless an output zone applied, so an uncast column rendered as the stored string (`2024-01-01 00:00:00`) and a cast one as Carbon's or PHP's JSON form. Core 1.7's response pivot schema lists every readable pivot field and declares a `DateTime` a `date-time`, and neither form is one, so every `Date`, `DateTime` or `Time` pivot value now renders through its field's format, as an attribute does and as the Symfony bundle already did. The Eloquent provider reads a date column the pivot model doesn't cast the way a `datetime` cast would, in the app's timezone, which is how Eloquent reads every date column it is told about.

## Consequences

- With no output zone set, a cast pivot date changes from Carbon's JSON form to the field's format, keeping the zone the pivot model gave it.
- ADR 0030's other option, refusing to parse uncast strings because they carry no zone, is reversed: the app's timezone is the zone Eloquent itself assumes for a stored date.
