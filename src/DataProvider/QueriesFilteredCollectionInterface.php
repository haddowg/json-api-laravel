<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An optional {@see DataProviderInterface} capability: hands a collection action the rows its
 * validated `filter[...]` parameters select, with no sort and no page window, through
 * {@see \haddowg\JsonApiLaravel\Action\FilteredCollection}. The criteria carry only the
 * filters the action takes, and the provider applies them through
 * {@see CriteriaApplier::applyFilters()}, so the defaults and the matching are the
 * collection fetch's own.
 *
 * A provider without it still serves {@see \haddowg\JsonApiLaravel\Action\FilteredCollection::each()},
 * from one unwindowed {@see DataProviderInterface::fetchCollection()}, and its
 * {@see \haddowg\JsonApiLaravel\Action\FilteredCollection::query()} is null.
 */
interface QueriesFilteredCollectionInterface
{
    /**
     * A fresh query for `$type` with the filters applied, starting from the same root query
     * the collection fetch uses, so the type's global scopes apply. Null when the provider has
     * no native query builder.
     *
     * @return Builder<Model>|null
     */
    public function queryFiltered(string $type, CollectionCriteria $criteria): ?Builder;

    /**
     * The filtered rows of `$type` in ascending id order, read `$batch` rows at a time.
     *
     * @param positive-int $batch
     *
     * @return iterable<object>
     */
    public function eachFiltered(string $type, CollectionCriteria $criteria, int $batch): iterable;
}
