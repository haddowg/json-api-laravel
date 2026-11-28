<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * The pivot extension of {@see DataProviderInterface}: a provider that reads the stored
 * pivot values of a `belongsToMany`, and may also query them. It declares the same four
 * methods as the Symfony bundle's interface of the same name.
 *
 * Every implementer reads pivot rows through {@see fetchRelatedPivotMap()} and
 * {@see fetchRelatedPivotMapBatch()}, which every response rendering a pivot relation's
 * linkage asks the related type's provider for, so each member carries its `meta.pivot`.
 * A relation with stored pivot fields whose related type's provider doesn't implement
 * this interface fails the boot check.
 *
 * {@see supportsPivot()} says whether the provider also queries those rows: the reference
 * {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider} fetches a
 * related page over the relation's join with {@see fetchRelatedPivotCollection()}, its
 * pivot fields filter and sort keys. The in-memory provider keeps its rows beside each
 * parent and answers false, so its related reads take the plain
 * {@see DataProviderInterface::fetchRelatedCollection()} path and a pivot filter or sort
 * key is unrecognised (`400`).
 *
 * @template-covariant TEntity of object
 */
interface PivotAwareProviderInterface
{
    /**
     * Whether `$relation`, whose members are of `$relatedType`, is a pivot-backed relation
     * this provider can query over its pivot rows: a
     * {@see \haddowg\JsonApi\Resource\Field\BelongsToMany} declaring stored pivot fields.
     * False routes the fetch to the plain
     * {@see DataProviderInterface::fetchRelatedCollection()} path; the stored values still
     * come from {@see fetchRelatedPivotMap()} and {@see fetchRelatedPivotMapBatch()}.
     */
    public function supportsPivot(string $relatedType, RelationInterface $relation): bool;

    /**
     * The related collection of `$relatedType` reachable from `$parent` through the pivot
     * `$relation`, scoped to the parent, then filtered on its pivot and related fields,
     * sorted and windowed per `$criteria`. Returns the page of members, the pre-window total
     * and each member's pivot values, read by the same query, for the related endpoint
     * (`GET /{type}/{id}/{rel}`), the relationship endpoint and the first page an include
     * renders.
     *
     * A cursor (keyset) window ({@see \haddowg\JsonApi\Pagination\CursorWindow}) returns
     * the cursor variant instead: the same page and pivot map, plus the boundary cursors the
     * provider minted.
     *
     * @return PivotCollectionResult<TEntity>|PivotCursorCollectionResult<TEntity>
     */
    public function fetchRelatedPivotCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): PivotCollectionResult|PivotCursorCollectionResult;

    /**
     * The pivot map for every member of `$parent`'s pivot `$relation`, with no window and
     * no filter, `memberId => [field => typed value]`, for a single parent whose members
     * were read without their pivot rows.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fetchRelatedPivotMap(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
    ): array;

    /**
     * The pivot map of every member of `$relation` for a whole page of `$parents` in one
     * read, the batched twin of {@see fetchRelatedPivotMap()} with no window and no filter,
     * so a document whose pivot relation's linkage data renders carries `meta.pivot` on each
     * identifier without a read per parent.
     *
     * Returns `parentWireId => [memberId => [field => typed value]]`, where the outer key is
     * the wire id the served `$parentType`'s serializer gives the parent and the inner map
     * keys each member by its wire id. A provider may key either map by the ids it stores
     * instead, in any case, which the type's id encoder decodes the wire ids to. A parent with
     * no rows may be left out.
     *
     * @param string       $parentType the served parent type whose encoder keys the outer map
     * @param list<object> $parents    the already-fetched page of parents
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function fetchRelatedPivotMapBatch(
        string $parentType,
        string $relatedType,
        array $parents,
        RelationInterface $relation,
    ): array;
}
