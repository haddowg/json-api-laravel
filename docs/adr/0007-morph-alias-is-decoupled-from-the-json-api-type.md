# The Eloquent morph alias is decoupled from the JSON:API resource type

- **Status:** accepted
- **Date:** 2026-07-04

**Context.** A polymorphic relation (`MorphTo`/`MorphToMany`) stores a morph **alias** per row
(Eloquent's `Relation::morphMap()` value, or the default FQCN) to resolve the related model
class. The JSON:API **type** is a separate, resource-declared name (`AbstractResource::$type`).
The PLAN watch item ("resource `type` should be able to differ from the morph alias; resolve
during Phase 3 design") is resolved here: the two are **decoupled**. The morph alias is
storage-internal — it only ever picks the model class when Eloquent hydrates a morph relation;
the JSON:API type is what the wire renders, resolved by the member object's serializer through
`RelationInterface::resolveSerializer()` → `SerializerInterface::getType()`. Nothing requires
the alias and the type to match.

**Consequences.**

- **A stored alias may differ freely from the rendered type.** The blog fixture proves it: the
  morph map registers `blog_author`/`blog_tag` aliases, deliberately distinct from the
  `authors`/`tags` JSON:API types, and a `MorphTo` feature stored as `blog_tag` renders as
  `type: "tags"`. The provider uses the alias only to load the model; core serialization never
  sees it.
- **The polymorphic member serializer is chosen by object type, never by alias.** For a
  `MorphTo` to-one and a heterogeneous `MorphToMany` to-many, the serializer is resolved among
  the relation's declared types by matching each member object's `getType()` — so an
  application is free to rename morph aliases (a storage migration) without touching the API
  surface, and vice versa.
- **No morph-map registration is required for the JSON:API layer.** Registering the map governs
  only Eloquent's own storage resolution; the resource declarations own the wire types.
