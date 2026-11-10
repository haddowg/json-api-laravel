<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Serializer;

use haddowg\JsonApi\Exception\NoResourceRegistered;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApi\Server\Server;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\DataProvider\RelationCriteriaFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The load state a server renders with. A model's to-many whose members come from its
 * provider's read, because the related type has a provider of its own, a filter default
 * narrows it or it has a read order, never counts as loaded here. Core asks only when no
 * linkage was supplied for the relation, so its data is then left out rather than read
 * off the model, where a relation something else loaded after the
 * {@see \haddowg\JsonApiLaravel\DataProvider\RelatedIncludeBatcher} ran still holds
 * every row its query returned.
 *
 * The batcher decides what to load from {@see isLoadedOnModel()}, the wrapped
 * storage answer.
 */
final class ProviderReadLoadState implements RelationshipLoadStateInterface
{
    /**
     * @param \Closure(): DataProviderRegistry $providers
     * @param \Closure(): Server               $server    the server this load state renders for
     */
    public function __construct(
        private readonly RelationshipLoadStateInterface $inner,
        private readonly \Closure $providers,
        private readonly \Closure $server,
    ) {}

    public function isRelationshipLoaded(mixed $model, RelationInterface $relation): bool
    {
        if (!$this->inner->isRelationshipLoaded($model, $relation)) {
            return false;
        }

        return !$model instanceof Model || !$relation->isToMany() || !$this->readsThroughProvider($relation);
    }

    /**
     * Whether the model already holds the relation's value, as storage reports it.
     */
    public function isLoadedOnModel(mixed $model, RelationInterface $relation): bool
    {
        return $this->inner->isRelationshipLoaded($model, $relation);
    }

    private function readsThroughProvider(RelationInterface $relation): bool
    {
        $providers = ($this->providers)();
        foreach ($relation->relatedTypes() as $relatedType) {
            if (!$providers->supportsType($relatedType)) {
                continue;
            }

            $provider = $providers->forType($relatedType);
            if (!$provider instanceof EloquentDataProvider && !$provider instanceof InMemoryDataProvider) {
                return true;
            }
        }

        $relatedTypes = $relation->relatedTypes();
        if (\count($relatedTypes) !== 1) {
            return false;
        }

        try {
            $relatedResource = ($this->server)()->resourceFor($relatedTypes[0]);
        } catch (NoResourceRegistered) {
            $relatedResource = null;
        }

        return RelationCriteriaFactory::readsThroughProvider($relatedResource, $relation);
    }
}
