# A scoped WhereMorph admits stored ids as a related read finds them

A stored polymorphic id may be the target's storage key or its wire id, while the target's key
may sit behind a cast such as a binary UUID, so comparing the column with the scoped keys in SQL
matches nothing. The filter decodes the requested ids, or the distinct ids the column holds,
through the type's encoder or the key's cast, looks them up under the global scopes and binds
them in chunks under every database's parameter limit. It keeps one subquery only where both
sides compare on every database: a plain key of the morph column's family, or a key behind
BinaryUuidEncoder read as its canonical text by a driver expression.
