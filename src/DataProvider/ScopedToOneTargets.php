<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Schema\Relationship\ToOneRelationshipLinkage;
use haddowg\JsonApi\Server\Server;

/**
 * Decides the target a to-one relation renders when its related type is served by its own
 * scoping provider (see {@see DataProviderRegistry::scopingProviderFor()}).
 *
 * When that provider is the related type's {@see DataProviderRegistry::scopedRelatedProvider()}
 * for the parent's type, {@see load()} first asks it for a whole page of parents at once through
 * {@see DataProviderInterface::fetchRelatedCollectionBatch()}. For a parent the batch answered,
 * the batch is the target: its first item, or none when the answer is empty. The parent needs no
 * relation method, attribute or hook for it.
 *
 * Any other parent reads its target off the parent as usual, which shows only if the provider's
 * {@see DataProviderInterface::fetchOne()} still returns it for this request; otherwise the
 * relation renders as none. The parent's own object is what renders, so a write through an
 * `on()` hop lands on the object the parent holds.
 *
 * Core asks for it through the to-one linkage seam on every to-one read (linkage, `included`,
 * the relationship endpoint, `on()` hops on read and write, `MorphTo`), and the handler asks
 * it for the to-one related endpoint. Answers are memoized per parent object and relation until
 * {@see forget()}, so the include preload walks the very objects the render shows.
 */
final class ScopedToOneTargets
{
    private ?Server $server = null;

    /**
     * @var \WeakMap<object, array<string, ToOneRelationshipLinkage|null>>
     */
    private \WeakMap $memo;

    /**
     * The relations of each parent {@see load()} already asked a batch for, and whether the
     * batch answered for that parent.
     *
     * @var \WeakMap<object, array<string, bool>>
     */
    private \WeakMap $batched;

    public function __construct(private readonly DataProviderRegistry $providers)
    {
        $this->memo = new \WeakMap();
        $this->batched = new \WeakMap();
    }

    /**
     * Sets the server whose serializers identify targets for the operation being handled, and
     * drops every memoized answer.
     */
    public function bind(?Server $server): void
    {
        $this->server = $server;
        $this->forget();
    }

    /**
     * Drops every memoized answer, so a target changed by a write is decided again.
     */
    public function forget(): void
    {
        $this->memo = new \WeakMap();
        $this->batched = new \WeakMap();
    }

    /**
     * Asks the related type's own provider for to-one `$relation`'s target on every parent in
     * `$parents`, which are of `$parentType`, in one {@see DataProviderInterface::fetchRelatedCollectionBatch()}.
     * A parent already asked for is not asked again, so a page's batch runs once however many
     * paths read it.
     *
     * Returns `null` when the relation does not load this way: a to-many, a polymorphic to-one,
     * or a related type with no scoped provider for this parent type. Otherwise it returns the
     * parents the batch answered, with their targets, and the parents it did not, whose target
     * is read off the parent as before.
     *
     * @param list<object> $parents
     *
     * @return array{answered: list<object>, unanswered: list<object>, targets: list<object>}|null
     */
    public function load(array $parents, string $parentType, RelationInterface $relation, JsonApiRequestInterface $request): ?array
    {
        $relatedTypes = $relation->relatedTypes();
        if ($relation->isToMany() || \count($relatedTypes) !== 1 || $this->server === null || !$this->server->hasSerializerFor($parentType)) {
            return null;
        }

        $provider = $this->providers->scopedRelatedProvider($parentType, $relatedTypes[0]);
        if ($provider === null) {
            return null;
        }

        $name = $relation->name();
        $asking = [];
        foreach ($parents as $parent) {
            if (!\array_key_exists($name, $this->batched[$parent] ?? [])) {
                $asking[\spl_object_id($parent)] = $parent;
            }
        }

        if ($asking !== []) {
            $serializer = $this->server->serializerFor($parentType);
            $batch = $provider->fetchRelatedCollectionBatch($parentType, \array_values($asking), $relation, $this->criteria($request), $request);
            foreach ($asking as $parent) {
                $wireId = $serializer->getId($parent);
                $this->batched[$parent] = [...($this->batched[$parent] ?? []), $name => $batch->has($wireId)];
                if ($batch->has($wireId)) {
                    $target = self::first($batch->for($wireId));
                    $this->memo[$parent] = [
                        ...($this->memo[$parent] ?? []),
                        $name => $target === null ? ToOneRelationshipLinkage::none() : ToOneRelationshipLinkage::to($target),
                    ];
                }
            }
        }

        $answered = [];
        $unanswered = [];
        $targets = [];
        foreach ($parents as $parent) {
            $linkage = ($this->memo[$parent] ?? [])[$name] ?? null;
            if ($linkage === null || (($this->batched[$parent] ?? [])[$name] ?? false) !== true) {
                $unanswered[] = $parent;

                continue;
            }

            $answered[] = $parent;
            if (\is_object($linkage->target)) {
                $targets[] = $linkage->target;
            }
        }

        return ['answered' => $answered, 'unanswered' => $unanswered, 'targets' => $targets];
    }

    /**
     * The target to render for to-one `$relation` on `$model`, `none()` when its provider hides
     * it, or `null` when no related type of the relation has its own scoping provider.
     */
    public function linkageFor(mixed $model, RelationInterface $relation, JsonApiRequestInterface $request): ?ToOneRelationshipLinkage
    {
        if ($relation->isToMany() || !\is_object($model) || !$this->relatesToScopingProvider($relation)) {
            return null;
        }

        $name = $relation->name();
        $entry = $this->memo[$model] ?? [];
        if (!\array_key_exists($name, $entry)) {
            $entry[$name] = $this->resolve($model, $relation, $request);
            $this->memo[$model] = $entry;
        }

        return $entry[$name];
    }

    /**
     * The target `$relation` renders on `$model`: the scoped answer when there is one,
     * otherwise the relation's own read.
     */
    public function targetFor(object $model, RelationInterface $relation, JsonApiRequestInterface $request): mixed
    {
        $linkage = $this->linkageFor($model, $relation, $request);

        return $linkage !== null ? $linkage->target : $relation->readValue($model, $request);
    }

    private function resolve(object $model, RelationInterface $relation, JsonApiRequestInterface $request): ?ToOneRelationshipLinkage
    {
        $target = $relation->readValue($model, $request);
        if (!\is_object($target)) {
            return ToOneRelationshipLinkage::none();
        }

        // Without a server the target cannot be identified, so it is withheld.
        $serializer = $this->server === null ? null : $relation->resolveSerializer($target, $this->server);
        if ($serializer === null) {
            return ToOneRelationshipLinkage::none();
        }

        $type = $serializer->getType($target);
        $provider = $this->providers->scopingProviderFor($type);
        if ($provider === null) {
            return null;
        }

        return $provider->fetchOne($type, $serializer->getId($target)) === null
            ? ToOneRelationshipLinkage::none()
            : ToOneRelationshipLinkage::to($target);
    }

    /**
     * The plain-include criteria: no filter, no sort and no window, so the batch answers with
     * each parent's target as it stands.
     */
    private function criteria(JsonApiRequestInterface $request): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters(fields: [], includes: [], sort: [], filter: [], pagination: $request->getPagination()),
            request: $request,
        );
    }

    /**
     * @param CollectionResult<object> $result
     */
    private static function first(CollectionResult $result): ?object
    {
        foreach ($result->items as $item) {
            if (\is_object($item)) {
                return $item;
            }
        }

        return null;
    }

    private function relatesToScopingProvider(RelationInterface $relation): bool
    {
        foreach ($relation->relatedTypes() as $type) {
            if ($this->providers->scopingProviderFor($type) !== null) {
                return true;
            }
        }

        return false;
    }
}
