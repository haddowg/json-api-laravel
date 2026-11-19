<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\Field\PivotRelationInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Through\PivotRowRelations;

/**
 * The relations a pivot row holds, asked of the provider that serves the pivot relation's
 * related type, the one that reads its pivot rows. A provider that implements no
 * {@see PivotRowRelations} holds no pivot rows, so it names none.
 *
 * @internal
 */
final class PivotRowRelationRouter implements PivotRowRelations
{
    private ?DataProviderRegistry $resolved = null;

    /**
     * @param DataProviderRegistry|\Closure(): DataProviderRegistry $providers the registry, or a closure building it the first time a path goes on past a pivot row
     */
    public function __construct(private readonly DataProviderRegistry|\Closure $providers) {}

    public function pivotRowRelation(PivotRelationInterface $relation, string $parentType, string $member): ?RelationInterface
    {
        $providers = $this->providers instanceof DataProviderRegistry ? $this->providers : ($this->resolved ??= ($this->providers)());
        foreach ($relation->relatedTypes() as $type) {
            if (!$providers->supportsType($type)) {
                continue;
            }

            $provider = $providers->forType($type);
            if ($provider instanceof PivotRowRelations) {
                return $provider->pivotRowRelation($relation, $parentType, $member);
            }
        }

        return null;
    }
}
