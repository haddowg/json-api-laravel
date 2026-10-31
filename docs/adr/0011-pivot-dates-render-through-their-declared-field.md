# Pivot dates render through their declared field

`meta.pivot` never passes through core's attribute rendering, so the handler renders each `Date`, `DateTime` or `Time` pivot value through its declared pivot field and output timezone, the way an attribute renders and the response schema declares it. A date column the pivot model does not cast is read as a `datetime` cast would read it, in the app's timezone, which is the zone Eloquent assumes for every stored date.

## Considered options

- **Render the value as the pivot model returns it.** An uncast column then renders as the stored string and a cast one as Carbon's JSON form, neither of which matches the documented format.
