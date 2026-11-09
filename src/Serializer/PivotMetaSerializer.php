<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Schema\Link\ResourceLinks;
use haddowg\JsonApi\Serializer\IncludeControlsInterface;
use haddowg\JsonApi\Serializer\RendersDeclaredLinksInterface;
use haddowg\JsonApi\Serializer\SerializerInterface;

/**
 * A {@see SerializerInterface} decorator that renders a `belongsToMany` pivot relation's
 * per-member pivot values as `meta.pivot`, mirroring how core's
 * {@see \haddowg\JsonApi\Serializer\PolymorphicSerializer} decorates a polymorphic relation
 * (the Laravel twin of the Symfony bundle's serializer). It wraps the related type's real
 * serializer and delegates everything to it EXCEPT {@see getMeta()}, where it merges the
 * member's pivot values (`pivotMap[id]`, read off Eloquent's pivot accessor via
 * {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider::fetchRelationshipPivot()})
 * under a top-level `pivot` key into the inner serializer's meta.
 *
 * Pivot meta rides core's existing `getMeta()` path, which the transformer renders into
 * BOTH the full resource (the related endpoint `GET /{type}/{id}/{rel}`) and the resource
 * identifier (the relationship-linkage endpoint `GET /{type}/{id}/relationships/{rel}`) —
 * so binding this serializer for those two renders needs no core change. A member with no
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
 * A computed pivot field is not in the map. Given the parent, this serializer evaluates it per
 * member through {@see BelongsToMany::computedPivotValues()}, so it renders with no pivot model
 * behind it and on the in-memory witness too. A member with no stored row renders only its
 * computed fields.
 *
 * The `pivot` key keeps the values namespaced so they never collide with the member
 * resource's own meta, and so a client can tell pivot data from intrinsic meta.
 */
final class PivotMetaSerializer implements SerializerInterface, IncludeControlsInterface, RendersDeclaredLinksInterface
{
    /**
     * @param SerializerInterface                 $inner    the related type's real serializer
     * @param array<string, array<string, mixed>> $pivotMap `memberId => [pivotField => typed value]`
     * @param BelongsToMany|null                  $relation       the pivot relation whose fields decide what renders; without it the map renders as given
     * @param object|null                         $parent         the parent whose relation renders, which computed pivot fields read; without it they render nothing
     * @param string|null                         $outputTimezone the server's default output zone for computed date fields
     */
    public function __construct(
        private readonly SerializerInterface $inner,
        private readonly array $pivotMap,
        private readonly ?BelongsToMany $relation = null,
        private readonly ?object $parent = null,
        private readonly ?string $outputTimezone = null,
    ) {}

    public function getType(mixed $object): string
    {
        return $this->inner->getType($object);
    }

    public function getId(mixed $object): string
    {
        return $this->inner->getId($object);
    }

    public function getMeta(mixed $object, JsonApiRequestInterface $request): array
    {
        $meta = $this->inner->getMeta($object, $request);

        $pivot = $this->pivotMap[$this->inner->getId($object)] ?? null;
        $computes = $this->relation !== null && $this->parent !== null && $this->relation->computedPivotFields() !== [];
        if ($this->relation !== null && ($pivot !== null || $computes)) {
            $stored = $pivot !== null;
            $values = $computes ? [...($pivot ?? []), ...$this->relation->computedPivotValues($this->parent, $object, $request, $this->outputTimezone)] : ($pivot ?? []);
            $readable = [];
            foreach ($this->relation->readablePivotFieldsFor($request, $object) as $field) {
                if ($stored || BelongsToMany::isComputedPivotField($field)) {
                    $readable[$field->name()] = $values[$field->name()] ?? null;
                }
            }
            $pivot = $readable;
        }

        if ($pivot !== null && $pivot !== []) {
            $meta['pivot'] = $pivot;
        }

        return $meta;
    }

    public function getLinks(mixed $object, JsonApiRequestInterface $request): ?ResourceLinks
    {
        return $this->inner->getLinks($object, $request);
    }

    public function renderDeclaredLinks(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner instanceof RendersDeclaredLinksInterface
            ? $this->inner->renderDeclaredLinks($object, $request)
            : [];
    }

    public function getAttributes(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getAttributes($object, $request);
    }

    public function getDefaultIncludedRelationships(mixed $object): array
    {
        return $this->inner->getDefaultIncludedRelationships($object);
    }

    public function getRelationships(mixed $object, JsonApiRequestInterface $request): array
    {
        return $this->inner->getRelationships($object, $request);
    }

    public function getNonIncludableRelationships(JsonApiRequestInterface $request, mixed $object): array
    {
        return $this->inner instanceof IncludeControlsInterface
            ? $this->inner->getNonIncludableRelationships($request, $object)
            : [];
    }

    public function maxIncludeDepth(): ?int
    {
        return $this->inner instanceof IncludeControlsInterface
            ? $this->inner->maxIncludeDepth()
            : null;
    }

    public function getAllowedIncludePaths(): ?array
    {
        return $this->inner instanceof IncludeControlsInterface
            ? $this->inner->getAllowedIncludePaths()
            : null;
    }

    public function getExcludedIncludePaths(): array
    {
        return $this->inner instanceof IncludeControlsInterface
            ? $this->inner->getExcludedIncludePaths()
            : [];
    }
}
