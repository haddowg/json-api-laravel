# The served YAML document is the served JSON body re-encoded

The YAML route decodes the JSON body the document route would send, after serve decorators and pruning, and dumps it as YAML, rather than building or warming a YAML artifact of its own. One decorated build then feeds both formats, so they can't drift, and an empty object survives as a map because the decode keeps objects. The ETag is the JSON route's tag with the format name hashed in, which keeps the 304 as cheap as JSON's and stops a cache handing one format's body to a request for the other.

## Consequences

- Each YAML response that isn't a 304 pays a decode and a dump. Nothing is warmed for it.
- The dump also passes `DUMP_EMPTY_ARRAY_AS_SEQUENCE`, so an empty JSON list stays a list. The export command and the static `public_path` files dump without it.
