<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Sort\SortMatcher;
use haddowg\JsonApi\Serializer\RelationOrderValidator;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\QueriesPivotColumnsInterface;
use haddowg\JsonApiLaravel\OpenApi\PivotFields;

/**
 * Checks a resource's to-many read orders at route registration and `jsonapi:optimize`: core's
 * {@see RelationOrderValidator}, and that an order only a pivot field answers is read by a
 * provider that queries the join. The in-memory provider holds no pivot data, so such an order
 * would otherwise do nothing on every read of the relation.
 */
final class RelationOrderGuard
{
    /**
     * @param \Closure(): DataProviderRegistry $providers resolved only when a pivot order needs a provider checked, so route registration builds no provider it doesn't need
     */
    public function __construct(private readonly \Closure $providers) {}

    /**
     * @throws \LogicException when a to-many's read order names a sort it can't apply
     */
    public function check(SerializerResolverInterface $resolver, string $type, AbstractResource $resource): void
    {
        (new RelationOrderValidator($resolver))->validate($type, $resource);

        foreach ($resource->allFields() as $relation) {
            if (!$relation instanceof BelongsToMany) {
                continue;
            }

            $order = $relation->defaultSort();
            $pivotKeys = \array_map(static fn(SortInterface $sort): string => $sort->key(), PivotFields::sortsFor($relation));
            $relatedType = $relation->relatedTypes()[0] ?? null;
            if ($order === [] || $pivotKeys === [] || $relatedType === null) {
                continue;
            }

            $providers = ($this->providers)();
            if (!$providers->supportsType($relatedType) || $providers->forType($relatedType) instanceof QueriesPivotColumnsInterface) {
                continue;
            }

            $related = $resolver->hasSerializerFor($relatedType) ? $resolver->serializerFor($relatedType) : null;
            $vocabulary = SortMatcher::merge($related instanceof AbstractResource ? $related->allSorts() : [], $relation->sorts());
            foreach ($order as $directive) {
                $key = $directive->sort->key();
                if (\in_array($key, $pivotKeys, true) && !SortMatcher::recognises($vocabulary, $key)) {
                    throw new \LogicException(\sprintf(
                        'Relation "%s" on resource type "%s" is ordered by the pivot field "%s", but the data provider of "%s" does not query the relation\'s join, so it cannot order by a pivot field. Order it by a field of "%s", or serve "%s" from a provider that reads pivot rows.',
                        $relation->name(),
                        $type,
                        $key,
                        $relatedType,
                        $relatedType,
                        $relatedType,
                    ));
                }
            }
        }
    }
}
