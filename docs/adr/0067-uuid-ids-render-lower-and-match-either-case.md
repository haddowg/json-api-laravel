# A uuid-shaped id renders in lower case and matches its key in either case

A guid kept in capitals rendered one id on its resource and another in linkage read from a column, and answered only to its stored case. A uuid-shaped id with no encoder now renders in lower case everywhere, and every lookup by id binds the key in both cases on the column side rather than wrapping the column in `LOWER()`, so its index still serves the query. A batched read matches a join or child row to its parent in either case, since a case-insensitive column can return one Eloquent's exact match would drop.
