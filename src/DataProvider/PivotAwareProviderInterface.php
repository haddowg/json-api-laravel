<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\Field\RelationInterface;

/**
 * A provider that reads the stored pivot values of a `belongsToMany` for many parents at
 * once, named and shaped as the Symfony bundle's interface of the same name. An included
 * pivot relation whose members carry no pivot row of their own renders its stored fields
 * from this map, read once for every parent of the type the document holds. A provider that
 * stores pivot values and doesn't implement it renders only the computed fields of an
 * included pivot relation.
 *
 * The bundle's interface also declares the related endpoint's and relationship endpoint's
 * pivot reads. Here those go through {@see QueriesPivotColumnsInterface} and
 * {@see DataProviderInterface::fetchRelationshipPivot()}.
 */
interface PivotAwareProviderInterface
{
    /**
     * The pivot values of every member of `$relation` for each of `$parents`, keyed by the
     * wire id the served `$parentType`'s serializer gives the parent, then by the member's
     * wire id. A parent with no members may be left out. The package asks the provider of
     * `$relatedType`.
     *
     * @param string       $parentType the served parent type whose id encoder keys the outer map
     * @param list<object> $parents    the already-fetched parents
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
