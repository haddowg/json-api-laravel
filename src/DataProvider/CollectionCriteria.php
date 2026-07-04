<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\WindowInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;

/**
 * Everything a {@see DataProviderInterface} needs to answer a collection fetch,
 * resolved by the operation handler from the operation and the resource declaration
 * so providers stay decoupled from core's `AbstractResource` API:
 *
 * - {@see $queryParameters} — the request's parsed query-parameter groups;
 * - {@see $filters} / {@see $sorts} — the **declared** vocabularies the requested
 *   `filter[…]`/`sort` keys are matched against (the resource's `filters()` /
 *   `allSorts()`); execution stays in the provider's handlers;
 * - {@see $defaultSort} — the resource's `defaultSort()` directives, applied **only
 *   when the request carries no `sort`**; an explicit `?sort=` overrides it entirely.
 *   Each directive's sort must be one of the declared {@see $sorts}. A default order
 *   keeps an otherwise unsorted collection — and its pagination window —
 *   deterministic;
 * - {@see $window} — the pagination fetch window to push down to the store, or `null`
 *   for an unpaginated fetch. Carried as the polymorphic {@see WindowInterface}; a
 *   provider narrows to the concrete window types it can execute (count-based providers
 *   handle {@see \haddowg\JsonApi\Pagination\OffsetWindow});
 * - {@see $aliasOf} — a routing hint mapping a filter/sort directive KEY to a non-root
 *   query alias the criteria should be applied on, so a single criteria can carry
 *   vocabulary spanning more than one alias of the same query (populated only on the
 *   Eloquent pivot related-collection path in a later phase). A key absent from the map
 *   resolves to the root alias, so the default `[]` keeps every directive on the root —
 *   the behaviour every non-pivot path and the entire in-memory provider have;
 * - {@see $wantsCount} — whether the handler resolved a `COUNT` for this windowed fetch.
 *   The provider issues the `COUNT` (the count-based page with `meta.page.total`/
 *   `links.last`) iff `true`, else fetches count-free (the window+1 probe → `hasMore`, no
 *   total). Defaulted `false` so every construction site stays count-free unless the
 *   handler explicitly asks for a count.
 */
final readonly class CollectionCriteria
{
    /**
     * @param list<FilterInterface> $filters     the filter vocabulary declared for the type
     * @param list<SortInterface>   $sorts       the sort vocabulary declared for the type
     * @param list<SortDirective>   $defaultSort the resource's default sort order, applied when no `sort` is requested
     * @param array<string, string> $aliasOf     directive KEY → target query alias; an absent key resolves to the root alias
     * @param bool                  $wantsCount  whether the provider should run the `COUNT` for this windowed fetch (default false = count-free)
     */
    public function __construct(
        public QueryParameters $queryParameters,
        public array $filters = [],
        public array $sorts = [],
        public ?WindowInterface $window = null,
        public array $defaultSort = [],
        public array $aliasOf = [],
        public bool $wantsCount = false,
    ) {}
}
