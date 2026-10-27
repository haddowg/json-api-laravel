# A morph relation is served as polymorphic whatever its type count

Core now documents a `MorphTo` or `MorphToMany` over one type as a union with no related-type query parameters (core ADR 0184), so the package decides what a request may ask of a relation by the same rule: polymorphic when it is a core `PolymorphicRelationInterface` or lists several types. A single-type morph's endpoints therefore reject the related type's filter, sort and relationship queries, as a multi-type one's do, while its members are still loaded in one query through that type's provider.
