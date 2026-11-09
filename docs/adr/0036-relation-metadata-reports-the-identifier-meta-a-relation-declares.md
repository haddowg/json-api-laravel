# Relation metadata reports the identifier meta a relation declares, and no provider is involved

The OpenAPI relation metadata implements core's `DeclaresIdentifierMeta` by handing on the relation's own `identifierMetaFields()`, so a declared identifier meta shape projects exactly as it does in the Symfony bundle and the documents stay byte-identical. The values come from the relation's resolver on every provider, so neither the Eloquent nor the in-memory provider reads or stores anything for them, unlike `meta.pivot`, which only a provider reading join rows can supply.
