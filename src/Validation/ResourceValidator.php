<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Validation;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Hydrator\Relationship\ToOneRelationship;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Constraint\CompareField;
use haddowg\JsonApi\Resource\Constraint\Comparison;
use haddowg\JsonApi\Resource\Constraint\ConstraintInterface;
use haddowg\JsonApi\Resource\Constraint\Nullable;
use haddowg\JsonApi\Resource\Constraint\Required;
use haddowg\JsonApi\Resource\Constraint\When;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Map;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Error\Error;
use haddowg\JsonApi\Schema\Error\ErrorSource;
use haddowg\JsonApiLaravel\Validation\Constraint\UniqueEntity;
use haddowg\JsonApiLaravel\Validation\Rules\NotNull;
use haddowg\JsonApiLaravel\Validation\Rules\ParsableDate;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Runs a resource's declared constraints against an incoming create/update document and
 * raises a {@see ValidationFailed} (`422`) carrying one pointer-bearing {@see Error} per
 * violation. This is the always-on bridge (PLAN decision 6) core never had: core stores
 * constraints as metadata but never executes them.
 *
 * Validation is **document-first**: the resource's `attributes` are validated as the
 * request sends them (so a violation maps cleanly to `/data/attributes/<name>`), before
 * hydration touches the entity. Each writable attribute field's constraints are filtered
 * by the create/update {@see \haddowg\JsonApi\Resource\Constraint\Context} and translated
 * by the {@see ConstraintTranslator}, then wrapped per the
 * {@see Required}/{@see Nullable} resolution into an `illuminate/validation` rule-set:
 *  - a create-required field gets `required` (present and non-empty), relaxing on update
 *    to `sometimes|required` (a partial update may omit it, but a supplied value must be
 *    non-empty);
 *  - an optional field gets `sometimes` (validated only when present);
 *  - a nullable field gets `nullable` (an explicit `null` skips the value rules), while a
 *    non-nullable field's typed value rules reject a present `null`;
 *  - unknown attributes are ignored (the hydrator ignores them too).
 *
 * A {@see Map} attribute (a structured nested object) validates its child constraints by
 * **recursion**: each present child is registered under a dot-notation key
 * (`address.postcode`) that mirrors the top-level resolution (same create/update context
 * per child), so a child violation maps to `/data/attributes/<map>/<child>` — the
 * implicit one-level cascade (ADR 0020).
 *
 * {@see CompareField} (a cross-field rule) is evaluated at the **document** level, after
 * the per-field pass, because the comparison needs the sibling field's value. The
 * built-in {@see UniqueEntity} folds into a pre-hydration `Rule::unique` on the owning
 * Eloquent table (PLAN decision 6), and any other {@see EntityConstraintInterface} is
 * deferred to the post-hydration {@see validateEntity()} seam.
 */
final class ResourceValidator
{
    public function __construct(
        private readonly ValidationFactory $validatorFactory,
        private readonly ConstraintTranslator $translator,
        private readonly JsonPointerBuilder $pointers,
    ) {}

    /**
     * Validates a create/update document against the resource's declared constraints.
     *
     * On an **update** ($creating === false) the already-loaded domain object is folded
     * in: the wire-form attribute map of the stored resource is **merged** under the
     * incoming partial (an incoming key overrides per key; an absent key keeps its stored
     * value), and that merged map is what the per-field and cross-field passes see — so a
     * cross-field/conditional rule depending on a sibling the partial did not re-send
     * evaluates against the resulting resource state, and a required-on-update field
     * present in stored state no longer spuriously fails. A stored value resolving to
     * `null` is dropped before the merge (folding it would flip an absent optional into a
     * present-null); an incoming explicit `null` still overrides (the partial merges
     * last). On create there is no existing object (ADR 0049).
     *
     * @param object|null $existingObject the already-loaded domain object on update (null on create),
     *                                    whose stored attribute values are folded under the incoming partial
     * @param object|null $subject        a domain instance of this type used only to resolve the Eloquent
     *                                    table/key for a {@see UniqueEntity} `Rule::unique` (the freshly
     *                                    instantiated blank on create, the loaded model on update); a
     *                                    non-Eloquent subject leaves uniqueness inert (Eloquent-only, PLAN §6)
     *
     * @throws ValidationFailed when the document violates the resource's constraints
     */
    public function validate(
        AbstractResource $resource,
        JsonApiRequestInterface $request,
        bool $creating,
        ?object $existingObject = null,
        ?object $subject = null,
    ): void {
        $data = $request->getResource();
        if (!\is_array($data)) {
            return; // a missing/malformed data member is core's concern, raised in hydration
        }

        $incoming = $data['attributes'] ?? [];
        if (!\is_array($incoming)) {
            return;
        }

        /** @var array<string, mixed> $incoming */
        $attributes = !$creating && $existingObject !== null
            ? \array_merge($this->storedAttributes($resource, $existingObject, $request), $incoming)
            : $incoming;

        $model = $existingObject instanceof Model
            ? $existingObject
            : ($subject instanceof Model ? $subject : null);

        /** @var array<string, list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>> $rules */
        $rules = [];
        /** @var list<array{0: string, 1: CompareField}> $compares */
        $compares = [];

        foreach ($resource->fields() as $field) {
            if ($field instanceof Id || $field instanceof RelationInterface) {
                continue;
            }
            // The read-only gate is request-aware (core ADR 0079): a field marked
            // readOnly(fn) is validated for a caller it is writable for and skipped for
            // one it is read-only for, so validation and hydration stay consistent.
            if ($field->isReadOnlyFor($creating, $request)) {
                continue;
            }

            $name = $field->name();
            $value = $attributes[$name] ?? null;
            $rules[$name] = $this->fieldRules($field, $creating, $request, $value);

            if ($field instanceof Map && \is_array($value)) {
                foreach ($this->mapChildRules($field, $creating, $request, $value) as $childKey => $childRules) {
                    $rules[$childKey] = $childRules;
                }
            }

            foreach ($this->compareConstraints($field, $creating) as $compare) {
                $compares[] = [$name, $compare];
            }

            $unique = $this->uniqueRule($resource, $field, $creating, $model, $attributes);
            if ($unique !== null) {
                $rules[$name][] = $unique;
            }
        }

        $errors = [];

        if ($rules !== []) {
            $bag = $this->validatorFactory->make($attributes, $rules)->errors();
            foreach ($bag->keys() as $key) {
                foreach ($bag->get($key) as $message) {
                    $errors[] = $this->error(\is_string($message) ? $message : '', (string) $key);
                }
            }

            // Cross-field rules run at the document level, where the sibling value is in
            // scope (the per-field pass sees each value in isolation).
            foreach ($compares as [$owner, $compare]) {
                $detail = $this->compareViolation($owner, $compare, $attributes);
                if ($detail !== null) {
                    $errors[] = $this->error($detail, $owner);
                }
            }
        }

        $ownIdError = $this->ownIdError($resource, $data);
        if ($ownIdError !== null) {
            $errors[] = $ownIdError;
        }

        if ($errors === []) {
            return;
        }

        throw new ValidationFailed($errors);
    }

    /**
     * Validates a **relationship-endpoint** linkage
     * (`PATCH`/`POST`/`DELETE …/relationships/<rel>`) before the persister applies it.
     *
     * The linkage's resource `type` is checked against the relation's declared related
     * types: a `type` that is not an accepted inverse type is a `409` resource-type conflict
     * ({@see RelationshipTypeUnacceptable}, mirroring core's create-path
     * {@see \haddowg\JsonApi\Exception\ResourceTypeUnacceptable}), the linkage twin of a
     * wrong `data.type` on a create — pointed at the endpoint linkage (`/data/type` for a
     * to-one, `/data/<index>/type` for a to-many member). A polymorphic relation accepts any
     * of its declared inverse types. An empty (clearing) to-one linkage carries no
     * identifier to check.
     *
     * Every linkage id is validated against the related type's declared id format (a `422` at
     * the linkage id — the create-direction of {@see ownIdError()}, ported from the bundle's
     * `endpointLinkageError`/whole-resource id pass; the related type is the member's OWN
     * linkage type, so polymorphic linkage resolves the right constraints), and a
     * `belongsToMany`'s pivot-`meta` merge-before-validate pass runs (fed by
     * `$mode`/`$existingPivot`).
     *
     * The same pass serves BOTH surfaces: with `$embeddedRelationName === null` (the default) it
     * validates a **relationship-mutation endpoint** body (`/data/type`, `/data/<n>/id`,
     * `/data/<n>/meta/pivot/<field>`); with a relationship name supplied it validates a linkage
     * embedded in a **whole-resource write**, pointing every error at
     * `/data/relationships/<rel>/data[/<n>]/…` — so an embedded violation locates the offending
     * linkage, not the resource's own `data.type`.
     *
     * @param array<string, array<string, mixed>>       $existingPivot        the relation's stored pivot rows by related id (the
     *                                                                         merge base for a pivot relation; unused by the type guard)
     * @param string|null                               $embeddedRelationName null = relationship-endpoint pointers; a name = the
     *                                                                         embedded `/data/relationships/<rel>/…` pointers
     * @param (\Closure(string): ?AbstractResource)|null $resolveResource      resolves a related type to its resource (for the id-format
     *                                                                         pass); null leaves id-format unchecked
     *
     * @throws RelationshipTypeUnacceptable when a linkage names an unacceptable resource type
     * @throws ValidationFailed             when a linkage id violates the related type's id format or a pivot meta is invalid
     */
    public function validateRelationshipLinkage(
        RelationInterface $relation,
        ToOneRelationship|ToManyRelationship $linkage,
        Mode $mode = Mode::Replace,
        array $existingPivot = [],
        ?JsonApiRequestInterface $request = null,
        ?string $embeddedRelationName = null,
        ?\Closure $resolveResource = null,
    ): void {
        if ($relation->relatedTypes() === []) {
            return; // defensive: every relation declares at least one type via make()
        }

        // A linkage naming an unacceptable resource type is a `409` and PRE-EMPTS the id-format
        // + pivot pass (a wrong type has no meaningful id/pivot to validate) — the linkage twin
        // of a create's wrong `data.type`.
        $typeErrors = [];
        if ($linkage instanceof ToOneRelationship) {
            $identifier = $linkage->resourceIdentifier;
            if ($identifier !== null) {
                $error = $this->linkageTypeConflict($relation, $identifier->type, $this->linkageTypePointer($embeddedRelationName, null));
                if ($error !== null) {
                    $typeErrors[] = $error;
                }
            }
        } else {
            foreach ($linkage->resourceIdentifiers as $index => $identifier) {
                $error = $this->linkageTypeConflict($relation, $identifier->type, $this->linkageTypePointer($embeddedRelationName, $index));
                if ($error !== null) {
                    $typeErrors[] = $error;
                }
            }
        }

        if ($typeErrors !== []) {
            throw new RelationshipTypeUnacceptable($typeErrors);
        }

        // The id-format bag + the pivot merge-before-validate bag (a `belongsToMany` with
        // declared pivot fields): each linkage id is checked against the related type's format,
        // and each member's writable pivot `meta` against the pivot fields' constraints in the
        // per-member new/existing context. `Mode::Remove` / a request-less call skip the pivot
        // pass; the id-format pass runs whenever a resource resolver is supplied.
        $isPivot = $mode !== Mode::Remove && $relation instanceof BelongsToMany && $relation->pivotFields() !== [] && $request !== null;

        $errors = [];
        if ($linkage instanceof ToOneRelationship) {
            $identifier = $linkage->resourceIdentifier;
            if ($identifier !== null) {
                $idError = $this->linkageIdError($relation, $identifier->type, $identifier->id, $this->linkageIdPointer($embeddedRelationName, null), $resolveResource);
                if ($idError !== null) {
                    $errors[] = $idError;
                }
                if ($isPivot && $request !== null && $relation instanceof BelongsToMany) {
                    foreach ($this->memberPivotErrors($relation, $identifier->id, $this->pivotMetaIn($identifier->meta), null, $existingPivot, $request, $embeddedRelationName) as $metaError) {
                        $errors[] = $metaError;
                    }
                }
            }
        } else {
            foreach ($linkage->resourceIdentifiers as $index => $identifier) {
                $idError = $this->linkageIdError($relation, $identifier->type, $identifier->id, $this->linkageIdPointer($embeddedRelationName, $index), $resolveResource);
                if ($idError !== null) {
                    $errors[] = $idError;
                }
                if ($isPivot && $request !== null && $relation instanceof BelongsToMany) {
                    foreach ($this->memberPivotErrors($relation, $identifier->id, $this->pivotMetaIn($identifier->meta), $index, $existingPivot, $request, $embeddedRelationName) as $metaError) {
                        $errors[] = $metaError;
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
    }

    /**
     * The `422` {@see Error} for a linkage id that violates the related type's declared id
     * format, or `null` when it passes / there is nothing to check. The related type is the
     * linkage's OWN `type` (polymorphic safe), falling back to the relation's single declared
     * type; its id format is the {@see Id} field's translated constraints (the same rules
     * {@see ownIdError()} runs the create-direction). An absent/empty id, an unresolvable
     * related type, an id field with no format constraints, or a null `$resolveResource` all
     * yield `null` (presence/shape is core's concern, not the format bridge's).
     */
    private function linkageIdError(RelationInterface $relation, string $linkageType, ?string $id, string $pointer, ?\Closure $resolveResource): ?Error
    {
        if ($resolveResource === null || !\is_string($id) || $id === '') {
            return null;
        }

        $relatedType = $linkageType !== '' ? $linkageType : ($relation->relatedTypes()[0] ?? null);
        if ($relatedType === null) {
            return null;
        }

        $resource = $resolveResource($relatedType);
        if (!$resource instanceof AbstractResource) {
            return null;
        }

        $idField = null;
        foreach ($resource->fields() as $field) {
            if ($field instanceof Id) {
                $idField = $field;

                break;
            }
        }

        if ($idField === null) {
            return null;
        }

        $rules = [];
        foreach ($idField->constraints() as $constraint) {
            foreach ($this->translator->translate($constraint) as $rule) {
                $rules[] = $rule;
            }
        }

        if ($rules === []) {
            return null; // the related type's id is unconstrained — any id passes
        }

        $bag = $this->validatorFactory->make(['id' => $id], ['id' => $rules])->errors();
        if (!$bag->has('id')) {
            return null;
        }

        return new Error(
            status: '422',
            code: 'VALIDATION_FAILED',
            title: 'Unprocessable Entity',
            detail: (string) $bag->first('id'),
            source: ErrorSource::fromPointer($pointer),
        );
    }

    /**
     * The linkage `type` pointer for the active surface: the embedded
     * `/data/relationships/<rel>/data[/<n>]/type` when a relation name is supplied, else the
     * relationship-endpoint `/data[/<n>]/type`.
     */
    private function linkageTypePointer(?string $embeddedRelationName, ?int $index): string
    {
        return $embeddedRelationName === null
            ? $this->pointers->forRelationshipEndpointLinkageType($index)
            : $this->pointers->forLinkageType($embeddedRelationName, $index);
    }

    /**
     * The linkage `id` pointer for the active surface (embedded vs relationship endpoint).
     */
    private function linkageIdPointer(?string $embeddedRelationName, ?int $index): string
    {
        return $embeddedRelationName === null
            ? $this->pointers->forRelationshipEndpointLinkageId($index)
            : $this->pointers->forLinkageId($embeddedRelationName, $index);
    }

    /**
     * The linkage pivot-`meta` pointer for the active surface (embedded vs relationship
     * endpoint).
     */
    private function linkageMetaPointer(?string $embeddedRelationName, string $field, ?int $index): string
    {
        return $embeddedRelationName === null
            ? $this->pointers->forRelationshipEndpointLinkageMeta($field, $index)
            : $this->pointers->forLinkageMeta($embeddedRelationName, $field, $index);
    }

    /**
     * The writable pivot values from a linkage member's `meta`, read from the nested
     * `meta.pivot` (symmetric with how pivot renders on reads). A missing or non-array
     * `pivot` yields an empty map.
     *
     * @param array<string, mixed> $meta the linkage member's full meta
     *
     * @return array<string, mixed>
     */
    private function pivotMetaIn(array $meta): array
    {
        $pivot = $meta['pivot'] ?? [];

        /** @var array<string, mixed> $pivot */
        return \is_array($pivot) ? $pivot : [];
    }

    /**
     * Validates ONE relationship-endpoint member's pivot `meta` in the per-member
     * new/existing context (the merge-before-validate pass): a member whose related id is in
     * `$existingPivot` is an existing row, so its stored pivot row is merged UNDER the
     * incoming meta and validated in the UPDATE context (a writable field absent from meta
     * keeps its stored value, so a partial reorder never spuriously fails a required field);
     * a member whose id is absent is a new row, validated in the CREATE context (a required
     * writable pivot field absent on a new row is a `422` before persist, never a DB NOT-NULL
     * `500`). Runs both the per-field pivot rules and the cross-pivot-field comparisons over
     * the merged meta, pointed at the endpoint linkage meta (`/data[/<index>]/meta/pivot/<field>`).
     *
     * @param array<string, mixed>                 $incoming      the parsed per-member pivot meta
     * @param array<string, array<string, mixed>>  $existingPivot the relation's stored pivot rows, by related id
     *
     * @return list<Error>
     */
    private function memberPivotErrors(
        BelongsToMany $relation,
        ?string $id,
        array $incoming,
        ?int $index,
        array $existingPivot,
        JsonApiRequestInterface $request,
        ?string $embeddedRelationName,
    ): array {
        $stored = $id !== null ? ($existingPivot[$id] ?? null) : null;
        $creating = $stored === null;
        $meta = $stored === null ? $incoming : \array_merge($stored, $incoming);

        $writable = $relation->writablePivotFields($creating);
        if ($writable === []) {
            return []; // no writable pivot field → nothing to validate
        }

        /** @var array<string, list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>> $rules */
        $rules = [];
        /** @var list<array{0: string, 1: CompareField}> $compares */
        $compares = [];
        foreach ($writable as $field) {
            /** @var mixed $value */
            $value = $meta[$field->name()] ?? null;
            $rules[$field->name()] = $this->fieldRules($field, $creating, $request, $value);
            foreach ($this->compareConstraints($field, $creating) as $compare) {
                $compares[] = [$field->name(), $compare];
            }
        }

        $errors = [];
        $bag = $this->validatorFactory->make($meta, $rules)->errors();
        foreach ($bag->keys() as $key) {
            foreach ($bag->get($key) as $message) {
                $errors[] = $this->pivotError(\is_string($message) ? $message : '', (string) $key, $index, $embeddedRelationName);
            }
        }

        // Cross-pivot-field comparisons over the MERGED meta (the pivot analogue of the
        // attribute compare loop), so an incoming `weight` compares against the merged
        // `position` even when the partial did not re-send it.
        foreach ($compares as [$owner, $compare]) {
            $detail = $this->compareViolation($owner, $compare, $meta);
            if ($detail !== null) {
                $errors[] = $this->pivotError($detail, $owner, $index, $embeddedRelationName);
            }
        }

        return $errors;
    }

    /**
     * A `422` {@see Error} for a pivot-`meta` violation, pointed at the linkage meta for the
     * active surface (`/data/relationships/<rel>/data[/<index>]/meta/pivot/<field>` embedded,
     * `/data[/<index>]/meta/pivot/<field>` at a relationship endpoint).
     */
    private function pivotError(string $detail, string $field, ?int $index, ?string $embeddedRelationName): Error
    {
        return new Error(
            status: '422',
            code: 'VALIDATION_FAILED',
            title: 'Unprocessable Entity',
            detail: $detail,
            source: ErrorSource::fromPointer($this->linkageMetaPointer($embeddedRelationName, $field, $index)),
        );
    }

    /**
     * The `409` {@see Error} for a linkage `type` that is not in the relation's accepted
     * related types, or `null` when it is acceptable / absent (an empty `type` is core's
     * presence/shape concern). Mirrors core's create-path
     * {@see \haddowg\JsonApi\Exception\ResourceTypeUnacceptable} status/code
     * (`409` / `RESOURCE_TYPE_UNACCEPTABLE`), the pointer locating the offending linkage.
     */
    private function linkageTypeConflict(RelationInterface $relation, string $type, string $pointer): ?Error
    {
        if ($type === '' || \in_array($type, $relation->relatedTypes(), true)) {
            return null;
        }

        return new Error(
            status: '409',
            code: 'RESOURCE_TYPE_UNACCEPTABLE',
            title: 'Resource type is unacceptable',
            detail: \sprintf("Resource type '%s' is unacceptable for relationship '%s'!", $type, $relation->name()),
            source: ErrorSource::fromPointer($pointer),
        );
    }

    /**
     * The post-hydration entity pass: validates the hydrated entity against the
     * resource's custom {@see EntityConstraintInterface} constraints (rules that need the
     * persisted object, not the request document). The write handler calls this after
     * hydration and before commit; it is a no-op for a resource that declares none.
     *
     * The built-in {@see UniqueEntity} is deliberately excluded — PLAN decision 6 folds
     * it into a pre-hydration `Rule::unique` in {@see validate()} — so this seam serves
     * genuinely-post-hydration custom checks, each translated to invokable rules run
     * against the entity.
     *
     * @throws ValidationFailed when the entity violates an entity-level constraint
     */
    public function validateEntity(AbstractResource $resource, object $entity, bool $creating): void
    {
        $errors = [];
        foreach ($resource->fields() as $field) {
            foreach ($field->constraints() as $constraint) {
                if ($constraint instanceof UniqueEntity) {
                    continue; // handled pre-hydration as Rule::unique
                }
                if (!$constraint instanceof EntityConstraintInterface || !$constraint->context()->appliesTo($creating)) {
                    continue;
                }

                $rules = $this->translator->translate($constraint);
                if ($rules === []) {
                    continue;
                }

                $name = $field->name();
                $bag = $this->validatorFactory->make([$name => $entity], [$name => $rules])->errors();
                foreach ($bag->get($name) as $message) {
                    $errors[] = $this->error(\is_string($message) ? $message : '', $name);
                }
            }
        }

        if ($errors === []) {
            return;
        }

        throw new ValidationFailed($errors);
    }

    /**
     * The stored resource's wire-form attribute map: resolves each attribute's serializer
     * closure ({@see AbstractResource::getAttributes()}) against the loaded domain object,
     * yielding the same wire representation a read would render. A value resolving to
     * `null` is omitted (a stored null carries no value to fold and would convert an
     * absent optional into a present-null); an incoming explicit null still overrides
     * because the partial merges on top.
     *
     * @return array<string, mixed> the non-null stored attribute wire values, by name
     */
    private function storedAttributes(AbstractResource $resource, object $existingObject, JsonApiRequestInterface $request): array
    {
        $stored = [];
        foreach ($resource->getAttributes($existingObject, $request) as $name => $resolve) {
            /** @var mixed $value */
            $value = $resolve($existingObject, $request, $name);
            if ($value === null) {
                continue;
            }

            $stored[$name] = $value;
        }

        return $stored;
    }

    /**
     * The rule-set for one attribute field: its translated value rules wrapped by the
     * create/update presence + nullability resolution.
     *
     * @return list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>
     */
    private function fieldRules(FieldInterface $field, bool $creating, JsonApiRequestInterface $request, mixed $value): array
    {
        $valueRules = $this->valueConstraints($field, $creating, $request);

        $isRequired = $this->hasPresenceRule($field, Required::class, $creating, $request, $value);
        $isNullable = $this->hasPresenceRule($field, Nullable::class, $creating, $request, $value);

        $presence = [];
        if ($creating && $isRequired) {
            if ($isNullable) {
                // Present (may be null) but not omitted.
                $presence = ['present', 'nullable'];
            } else {
                $presence = ['required'];
            }
        } else {
            // A partial update never requires a member, and an optional field is only
            // validated when present.
            $presence = ['sometimes'];
            if ($isRequired) {
                $presence[] = 'required'; // required-on-update: if present, non-empty
            }
            if ($isNullable) {
                $presence[] = 'nullable';
            }
        }

        // A non-nullable, non-required field whose value rules do not themselves reject a
        // present `null` (a bare Str/Boolean with no typed rule) would otherwise let an
        // explicit `null` through validation and 500 in hydration (a TypeError on a
        // non-nullable POPO property / a NOT NULL QueryException on Eloquent). Append the
        // shipped NotNull guard so a present `null` is a clean 422 at the field's pointer;
        // a required field's `required` rule already rejects it, and a nullable field
        // accepts it, so it is added only when neither applies.
        $guards = [];
        if (!$isNullable && !$isRequired) {
            $guards[] = new NotNull();
        }

        return [...$presence, ...$guards, ...$valueRules];
    }

    /**
     * The translated value rules a field declares in this context, excluding the presence
     * markers ({@see Required}/{@see Nullable}, resolved by {@see fieldRules()}), the
     * cross-field {@see CompareField} (evaluated document-level) and every
     * {@see EntityConstraintInterface} (validated against the entity / folded into
     * `Rule::unique`). A {@see Map} field also carries a leading `array` rule.
     *
     * @return list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>
     */
    private function valueConstraints(FieldInterface $field, bool $creating, JsonApiRequestInterface $request): array
    {
        $rules = [];
        if ($field instanceof Map) {
            $rules[] = 'array';
        }
        // A DateTime field (and its Date/Time specialisations) carries no implicit format
        // rule, so a present, unparseable string would pass validation and 500 in core's
        // hydration (`new \DateTimeImmutable('banana')` throws). The ParsableDate guard is
        // the write-body twin of the filter validator's date-range check: a calendar-garbage
        // value is a clean 422 at /data/attributes/<name> before hydration. (An explicit
        // null on a nullable DateTime is short-circuited by the `nullable` presence marker,
        // so the guard never rejects a legitimate clear.)
        if ($field instanceof DateTime) {
            $rules[] = new ParsableDate();
        }

        foreach ($field->constraints() as $constraint) {
            if (!$constraint->context()->appliesTo($creating)) {
                continue;
            }
            if ($constraint instanceof Required || $constraint instanceof Nullable) {
                continue; // resolved by fieldRules()
            }
            if ($constraint instanceof CompareField) {
                continue; // cross-field; document-level
            }
            if ($constraint instanceof EntityConstraintInterface) {
                continue; // entity pass / Rule::unique
            }
            foreach ($this->translator->translate($constraint, $request) as $rule) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * The dot-notation child rule-sets for a present {@see Map} value, one per writable
     * child, keyed `<map>.<child>` so Laravel validates each nested child and a violation
     * maps to `/data/attributes/<map>/<child>`. Registered only when the map value is a
     * present array, so an omitted optional map never fires its required children (ADR
     * 0020). The recursion is one level deep by design — a child that is itself a Map is
     * not descended into.
     *
     * @param array<array-key, mixed> $value the incoming map value
     *
     * @return array<string, list<string|\Stringable|\Illuminate\Contracts\Validation\ValidationRule|\Illuminate\Contracts\Validation\Rule>>
     */
    private function mapChildRules(Map $map, bool $creating, JsonApiRequestInterface $request, array $value): array
    {
        $rules = [];
        foreach ($map->children() as $child) {
            // A Map child's visibility (read-only) stays static — request-aware child
            // predicates are an explicit non-goal (ADR 0020) — but its when() condition
            // still sees the request via fieldRules().
            if ($child->isReadOnly($creating)) {
                continue;
            }

            /** @var mixed $childValue */
            $childValue = $value[$child->name()] ?? null;
            $rules[$map->name() . '.' . $child->name()] = $this->fieldRules($child, $creating, $request, $childValue);
        }

        return $rules;
    }

    /**
     * The `Rule::unique` for the built-in {@see UniqueEntity} constraints a field
     * declares in this context, or `null` when the field carries none or the backing is
     * not an Eloquent model (in-memory/POPO → uniqueness inert, PLAN §6). On update the
     * current record is excluded via `->ignore($model)`; a composite unique adds a
     * `where` per sibling field over the merged attributes.
     *
     * @param array<string, mixed> $attributes the merged attribute map (for composite siblings)
     */
    private function uniqueRule(AbstractResource $resource, FieldInterface $field, bool $creating, ?Model $model, array $attributes): ?\Illuminate\Validation\Rules\Unique
    {
        if ($model === null) {
            return null;
        }

        $unique = null;
        foreach ($field->constraints() as $constraint) {
            if (!$constraint instanceof UniqueEntity || !$constraint->context()->appliesTo($creating)) {
                continue;
            }
            $unique = $constraint;
        }

        if ($unique === null) {
            return null;
        }

        $rule = Rule::unique($model::class, $field->column() ?? $field->name());
        if (!$creating) {
            $rule->ignore($model);
        }

        foreach ($unique->fields as $sibling) {
            if ($sibling === $field->name()) {
                continue;
            }
            $siblingField = $this->fieldByName($resource, $sibling);
            $column = $siblingField?->column() ?? $sibling;
            /** @var mixed $siblingValue */
            $siblingValue = $attributes[$sibling] ?? null;
            $rule->where($column, match (true) {
                \is_string($siblingValue), \is_int($siblingValue), \is_bool($siblingValue) => $siblingValue,
                \is_float($siblingValue) => (string) $siblingValue,
                default => null,
            });
        }

        return $rule;
    }

    /**
     * Validates a client-supplied `data.id` against the owning resource's id format — the
     * create-direction of the id-format helper. An absent/non-string id is left to core;
     * a resource whose id declares no format, or forbids a client id, passes any supplied
     * id (a forbidden type is core's `403`, so format-checking it would pre-empt that).
     *
     * @param array<string, mixed> $data the write body's `data` member
     */
    private function ownIdError(AbstractResource $resource, array $data): ?Error
    {
        $id = $data['id'] ?? null;
        if (!\is_string($id) || $id === '') {
            return null;
        }

        $idField = null;
        foreach ($resource->fields() as $field) {
            if ($field instanceof Id) {
                $idField = $field;

                break;
            }
        }

        if ($idField === null || !$idField->allowsClientId()) {
            return null;
        }

        $rules = [];
        foreach ($idField->constraints() as $constraint) {
            foreach ($this->translator->translate($constraint) as $rule) {
                $rules[] = $rule;
            }
        }

        if ($rules === []) {
            return null;
        }

        $bag = $this->validatorFactory->make(['id' => $id], ['id' => $rules])->errors();
        if (!$bag->has('id')) {
            return null;
        }

        return new Error(
            status: '422',
            code: 'VALIDATION_FAILED',
            title: 'Unprocessable Entity',
            detail: $bag->first('id'),
            source: ErrorSource::fromPointer('/data/id'),
        );
    }

    /**
     * The resource field with the given name, or `null` when none matches.
     */
    private function fieldByName(AbstractResource $resource, string $name): ?FieldInterface
    {
        foreach ($resource->fields() as $field) {
            if ($field->name() === $name) {
                return $field;
            }
        }

        return null;
    }

    private function error(string $detail, string $key): Error
    {
        return new Error(
            status: '422',
            code: 'VALIDATION_FAILED',
            title: 'Unprocessable Entity',
            detail: $detail,
            source: ErrorSource::fromPointer($this->pointers->forAttribute($key)),
        );
    }

    /**
     * The cross-field comparison constraints a field declares, filtered by context.
     *
     * @return list<CompareField>
     */
    private function compareConstraints(FieldInterface $field, bool $creating): array
    {
        $compares = [];
        foreach ($field->constraints() as $constraint) {
            if ($constraint instanceof CompareField && $constraint->context()->appliesTo($creating)) {
                $compares[] = $constraint;
            }
        }

        return $compares;
    }

    /**
     * The detail of a violated cross-field comparison, or `null` when it holds or cannot
     * run (a value absent or null — presence is the Required rule's concern).
     *
     * @param array<string, mixed> $attributes
     */
    private function compareViolation(string $owner, CompareField $compare, array $attributes): ?string
    {
        if (!\array_key_exists($owner, $attributes) || !\array_key_exists($compare->field, $attributes)) {
            return null;
        }

        /** @var mixed $value */
        $value = $attributes[$owner];
        /** @var mixed $other */
        $other = $attributes[$compare->field];
        if ($value === null || $other === null || $this->satisfies($compare->operator, $value, $other)) {
            return null;
        }

        return \sprintf('This value should be %s the value of "%s".', $this->describe($compare->operator), $compare->field);
    }

    private function satisfies(Comparison $operator, mixed $value, mixed $other): bool
    {
        [$left, $right] = $this->comparable($value, $other);
        $order = $left <=> $right;

        return match ($operator) {
            Comparison::EqualTo => $order === 0,
            Comparison::NotEqualTo => $order !== 0,
            Comparison::GreaterThan => $order > 0,
            Comparison::GreaterThanOrEqual => $order >= 0,
            Comparison::LessThan => $order < 0,
            Comparison::LessThanOrEqual => $order <= 0,
        };
    }

    /**
     * Coerces a pair of raw values to a comparable pair: two numbers, two dates, or — as
     * a fallback — the raw values for a loose comparison. A spaceship over the result
     * compares by value (dates chronologically).
     *
     * @return array{mixed, mixed}
     */
    private function comparable(mixed $value, mixed $other): array
    {
        if (\is_numeric($value) && \is_numeric($other)) {
            return [(float) $value, (float) $other];
        }

        $date = $this->asDate($value);
        $otherDate = $this->asDate($other);
        if ($date !== null && $otherDate !== null) {
            return [$date, $otherDate];
        }

        return [$value, $other];
    }

    private function asDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function describe(Comparison $operator): string
    {
        return match ($operator) {
            Comparison::EqualTo => 'equal to',
            Comparison::NotEqualTo => 'not equal to',
            Comparison::GreaterThan => 'greater than',
            Comparison::GreaterThanOrEqual => 'greater than or equal to',
            Comparison::LessThan => 'less than',
            Comparison::LessThanOrEqual => 'less than or equal to',
        };
    }

    /**
     * Whether a presence rule of `$ruleClass` ({@see Required}/{@see Nullable}) applies in
     * this context, looking at the field's own constraints and inside any {@see When}
     * whose (widened) condition holds for the caller/value — the seam that lets a
     * `when()`-wrapped presence rule be request-aware (ADR 0084).
     *
     * @param class-string<ConstraintInterface> $ruleClass
     */
    private function hasPresenceRule(FieldInterface $field, string $ruleClass, bool $creating, JsonApiRequestInterface $request, mixed $value): bool
    {
        foreach ($field->constraints() as $constraint) {
            if (!$constraint->context()->appliesTo($creating)) {
                continue;
            }
            if ($constraint instanceof $ruleClass) {
                return true;
            }
            if ($constraint instanceof When && ($constraint->condition)($value, $request) === true) {
                foreach ($constraint->constraints as $inner) {
                    if ($inner instanceof $ruleClass && $inner->context()->appliesTo($creating)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
