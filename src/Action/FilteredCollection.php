<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Action;

use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderInterface;
use haddowg\JsonApiLaravel\DataProvider\QueriesFilteredCollectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The rows a collection action's validated `filter[...]` parameters select from its mount
 * type, read from {@see ActionContext::filtered()}. The invoker has already checked the keys
 * and values and authorized `viewAny`, so the handler only reads.
 *
 * The rows are the ones the collection fetch would list for the same filters, through the
 * same provider and so under the same access scope, but with no sort and no page window.
 */
final readonly class FilteredCollection
{
    private QueriesFilteredCollectionInterface $provider;

    /**
     * @param DataProviderInterface<object> $provider
     *
     * @throws \LogicException when the provider cannot read a filtered collection
     *
     * @internal built by the {@see ActionInvoker}
     */
    public function __construct(
        DataProviderInterface $provider,
        private string $type,
        private CollectionCriteria $criteria,
    ) {
        if (!$provider instanceof QueriesFilteredCollectionInterface) {
            throw new \LogicException(\sprintf(
                'The data provider %s for JSON:API type "%s" does not implement %s, so a collection action cannot read '
                . 'the type\'s filtered rows. Implement it on the provider, or drop filters from the action.',
                $provider::class,
                $type,
                QueriesFilteredCollectionInterface::class,
            ));
        }

        $this->provider = $provider;
    }

    /**
     * A fresh Eloquent query on each call, with the filters applied and the type's global
     * scopes in place, and no sort or limit. Null when the type's provider has no query
     * builder, such as the in-memory provider.
     *
     * @return Builder<Model>|null
     */
    public function query(): ?Builder
    {
        return $this->provider->queryFiltered($this->type, $this->criteria);
    }

    /**
     * Every filtered row, in ascending id order, read `$batch` rows at a time.
     *
     * @return iterable<object>
     */
    public function each(int $batch = 500): iterable
    {
        if ($batch < 1) {
            throw new \InvalidArgumentException(\sprintf('The batch size must be at least 1, %d given.', $batch));
        }

        return $this->provider->eachFiltered($this->type, $this->criteria, $batch);
    }
}
