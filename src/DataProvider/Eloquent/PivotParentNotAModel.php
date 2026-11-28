<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider\Eloquent;

use haddowg\JsonApiLaravel\DataProvider\PivotAwareProviderInterface;

/**
 * The Eloquent provider was asked for the pivot rows of a parent that isn't an Eloquent
 * model, so it has no `belongsToMany` join to read them through: a custom provider serves the
 * parent type while the Eloquent provider serves the related type.
 *
 * @internal
 */
final class PivotParentNotAModel extends \LogicException
{
    public static function for(string $relation, object $parent, ?string $parentType, ?string $parentProvider): self
    {
        return new self(\sprintf(
            'The relation "%s" reads its pivot rows through %s, but its parent %s%s is not an Eloquent model, so there is no belongsToMany join to read them over. Serve the related type from a provider that implements %s for these parents, answering supportsPivot() false and reading the rows in fetchRelatedPivotMap() and fetchRelatedPivotMapBatch(), or serve the parent type from the Eloquent provider.',
            $relation,
            EloquentDataProvider::class,
            $parent::class,
            $parentType === null ? '' : \sprintf(' of type "%s", served by %s,', $parentType, $parentProvider ?? 'no provider'),
            PivotAwareProviderInterface::class,
        ));
    }
}
