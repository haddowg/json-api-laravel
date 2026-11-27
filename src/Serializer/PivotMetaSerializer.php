<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\StoredIdForms;
use haddowg\JsonApi\Resource\ResourceMeta;
use haddowg\JsonApi\Serializer\RendersResourceMetaInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A {@see SerializerInterface} decorator that renders a `belongsToMany` pivot relation's
 * per-member pivot values as `meta.pivot`, mirroring how core's
 * {@see \haddowg\JsonApi\Serializer\PolymorphicSerializer} decorates a polymorphic relation
 * (the Laravel twin of the Symfony bundle's serializer). It wraps the related type's real
 * serializer and delegates everything to it, every optional serializer interface included
 * ({@see AbstractPivotParentSerializer}), EXCEPT {@see getMeta()} and
 * {@see renderResourceMeta()}, where it merges the
 * member's pivot values (`pivotMap[id]`, read off the fetched members' pivot rows or the
 * related type's {@see \haddowg\JsonApiLaravel\DataProvider\PivotAwareProviderInterface})
 * under a top-level `pivot` key into the inner serializer's meta. Bound for one parent through
 * an include ({@see PivotRenderingResolver}), it has no map and reads each member's stored
 * values through `$storedPivotOf` instead.
 *
 * The transformer renders a full resource (the related endpoint `GET /{type}/{id}/{rel}`)
 * through `renderResourceMeta()`, which adds the pivot to the inner serializer's declared
 * resource meta, and a resource identifier (the relationship-linkage endpoint
 * `GET /{type}/{id}/relationships/{rel}`) through `getMeta()`. A member with no
 * pivot entry (the in-memory witness returns none — the documented pivot boundary) renders
 * the inner meta unchanged, so `meta.pivot` is Eloquent-only.
 *
 * A member's `meta.pivot` carries exactly core's
 * {@see BelongsToMany::readablePivotFieldsFor()} for the request and that member: a field
 * hidden or write-only for it (its `hidden(when:)` predicate receives the member) stays out,
 * and every other field renders, `null` when the join row holds no value, which is what the
 * response pivot schema's `required` promises. The map itself keeps write-only values,
 * because the validator reads them when it merges a partial pivot update.
 *
 * A field read through a hook, a computed one or a stored one with `extractUsing()`, renders
 * from {@see BelongsToMany::hookedPivotValues()}: given the parent, this serializer evaluates
 * it per member with the member's stored values, so the hook sees a `PivotEdge` and never the
 * pivot model. A computed field is not in the map, so it renders with no pivot model behind
 * it. A member with no stored row renders only its computed fields.
 *
 * The `pivot` key keeps the values namespaced so they never collide with the member
 * resource's own meta, and so a client can tell pivot data from intrinsic meta.
 */
final class PivotMetaSerializer extends AbstractPivotParentSerializer
{
    /**
     * @param SerializerInterface                 $inner    the related type's real serializer
     * @param array<string, array<string, mixed>> $pivotMap `memberId => [pivotField => typed value]`
     * @param BelongsToMany|null                  $relation       the pivot relation whose fields decide what renders; without it the map renders as given
     * @param object|null                         $parent         the parent whose relation renders, which computed pivot fields read; without it they render nothing
     * @param string|null                         $outputTimezone the server's default output zone for computed date fields
     * @param (\Closure(object): ?array<string, mixed>)|null $storedPivotOf reads a member's stored values when the map holds none for it, `null` when it has no stored row
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly array $pivotMap,
        private readonly ?BelongsToMany $relation = null,
        private readonly ?object $parent = null,
        private readonly ?string $outputTimezone = null,
        private readonly ?\Closure $storedPivotOf = null,
    ) {}

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->withPivot($this->inner->getMeta($object, $request), $object, $request);
    }

    public function renderResourceMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        $meta = $this->inner instanceof RendersResourceMetaInterface
            ? $this->inner->renderResourceMeta($object, $request)
            : ResourceMeta::render($this->inner, $object, $request);

        return $this->withPivot($meta, $object, $request);
    }

    /**
     * `$meta` with the member's pivot values under `pivot`.
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function withPivot(array $meta, mixed $object, JsonApiRequestInterface $request): array
    {
        $id = $this->inner->getId($object);
        $pivot = $this->pivotMap[$id]
            ?? $this->byComparableId()[StoredIdForms::comparable($id)]
            ?? ($this->storedPivotOf !== null && \is_object($object) ? ($this->storedPivotOf)($object) : null);
        if ($this->relation === null) {
            if ($pivot !== null) {
                $meta['pivot'] = $pivot === [] ? new \stdClass() : $pivot;
            }

            return $meta;
        }

        // A relation declaring no pivot field, an additionalProperties entry at most, renders
        // none. Stored fields render wherever pivot rows were read, `null` for a member with
        // no row; computed fields render wherever the parent is known.
        if ($this->relation->pivotFields() === []) {
            return $meta;
        }

        $stored = $pivot !== null || $this->storedPivotOf !== null || $this->pivotMap !== [];
        $computes = $this->parent !== null && $this->relation->computedPivotFields() !== [];
        if (!$stored && !$computes) {
            return $meta;
        }

        $values = $this->parent !== null
            ? [...($pivot ?? []), ...$this->relation->hookedPivotValues($this->parent, $object, $pivot ?? [], $request, $this->outputTimezone)]
            : ($pivot ?? []);
        $readable = [];
        foreach ($this->relation->readablePivotFieldsFor($request, $object) as $field) {
            if ($stored || BelongsToMany::isComputedPivotField($field)) {
                $readable[$field->name()] = $values[$field->name()] ?? null;
            }
        }

        // A member whose every pivot field is hidden for this request still carries the
        // object, empty, as the response pivot schema requires.
        $meta['pivot'] = $readable === [] ? new \stdClass() : $readable;

        return $meta;
    }

    /**
     * The pivot map keyed by each member's comparable id, so a uuid-shaped id the provider
     * keyed in capitals matches the lower case the member renders in.
     *
     * @return array<string, array<string, mixed>>
     */
    private function byComparableId(): array
    {
        $keyed = [];
        foreach ($this->pivotMap as $id => $values) {
            $keyed[StoredIdForms::comparable((string) $id)] = $values;
        }

        return $keyed;
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getRelationships($object, $request);
    }

    protected function inner(): SerializerInterface
    {
        return $this->inner;
    }
}
