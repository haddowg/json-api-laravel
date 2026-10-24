# A to-many relationship endpoint pages by default

- **Status:** accepted

`GET /{type}/{id}/relationships/{rel}` on a monomorphic, non-pivot to-many now serves page 1 of
the relation's paginator (relation, then related resource, then server default) even with no
query string, where it used to render the whole association unless the request carried
`?page`/`?sort`/`?filter`. The OpenAPI document already advertised that default page size and the
Symfony bundle already served it, so the unqueried full set was a divergence a client could not
see in the spec. A relation declared `withoutPagination()` still serves its whole linkage. This
replaces the "plain read renders the whole association" clause of ADR 0010 for that shape only:
polymorphic and pivot to-many relationship endpoints still render the whole association and
still reject query parameters with a `400`.
