<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\DefaultSortedRelationInterface;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\WhereGroup;
use haddowg\JsonApi\Resource\Filter\WhereIdIn;
use haddowg\JsonApi\Resource\Filter\WhereIdNotIn;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use haddowg\JsonApi\Resource\Sort\SortInterface;
use haddowg\JsonApi\Resource\Through\ReachesThrough;
use haddowg\JsonApi\Resource\Through\RelationPath;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\FilterPaths;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Checks the filters and sorts a type declares with `->through()`, and those its relations
 * scope to their related types, at `jsonapi:optimize`:
 *
 *  - every sort reaching through relations resolves, the type's own, its default order and
 *    each relation's, so a path naming no relation, crossing a to-many or reaching no stored
 *    field fails the deploy. A filter's path is resolved by {@see FilterPathGuard};
 *  - on a type an Eloquent model serves, each path is one the Eloquent provider can run: every
 *    segment is a relation method of the model it starts from, a sort's a to-one, every hop
 *    reaches a type an Eloquent model serves and no other provider scopes, since a `whereHas`
 *    applies only the related model's global scopes, and the leaf is a column, or for an
 *    existence filter a relation, of the model the path reaches.
 *
 * A filter or sort family resolves per request, so only what it returns is checked, when it is
 * matched.
 */
final class ThroughPathGuard
{
    public function __construct(private readonly DataProviderRegistry $providers) {}

    /**
     * The problems with `$resource`'s paths, each naming the type and the server.
     *
     * @return list<string>
     */
    public function check(SerializerResolverInterface $server, string $serverName, AbstractResource $resource): array
    {
        $type = $resource::$type;
        $problems = [];
        $resolver = ThroughPathResolver::forResolver($server);

        try {
            self::resolveSorts($resolver, self::sortsOf($resource), $resource->allFields());
            foreach (self::relatedResources($server, $resource) as [$relation, $related]) {
                self::resolveSorts($resolver, self::relationSorts($relation), $related->allFields());
            }
        } catch (\LogicException $e) {
            $problems[] = \sprintf('The JSON:API resource for type "%s" on server "%s" declares a sort that cannot run: %s', $type, $serverName, $e->getMessage());
        }

        $model = $this->modelFor($type);
        if ($model !== null) {
            $declared = [...$resource->allFilters(), ...self::sortsOf($resource)];
            $this->guardPaths($serverName, $type, null, $type, $model, $declared, FilterPaths::of($server, $resource), $problems);
        }

        foreach (self::relatedResources($server, $resource) as [$relation, $related]) {
            $relatedType = $relation->relatedTypes()[0];
            $relatedModel = $this->modelFor($relatedType);
            if ($relatedModel === null) {
                continue;
            }

            $declared = [...$relation->allFilters(), ...self::relationSorts($relation)];
            $this->guardPaths($serverName, $type, $relation, $relatedType, $relatedModel, $declared, FilterPaths::of($server, $related, $type), $problems);
        }

        return $problems;
    }

    /**
     * @param class-string<Model>                 $model    the model the filters and sorts run against
     * @param list<FilterInterface|SortInterface> $declared
     * @param list<string>                        $problems
     */
    private function guardPaths(string $serverName, string $type, ?RelationInterface $owner, string $runsOn, string $model, array $declared, ?FilterPaths $paths, array &$problems): void
    {
        if ($paths === null) {
            return;
        }

        foreach ($declared as $item) {
            if ($item instanceof WhereGroup) {
                $this->guardPaths($serverName, $type, $owner, $runsOn, $model, $item->children, $paths, $problems);

                continue;
            }

            // An id filter keyed by a relation reaches through it once resolved.
            if (!$item instanceof ReachesThrough || ($item->throughRelations() === null && !$item instanceof WhereIdIn && !$item instanceof WhereIdNotIn)) {
                continue;
            }

            try {
                $resolved = $item instanceof SortInterface ? $paths->resolveSort($item) : ($item instanceof FilterInterface ? $paths->resolve($item) : $item);
            } catch (\LogicException) {
                continue; // reported where the path resolves
            }
            if (!$resolved instanceof ReachesThrough || $resolved->throughRelations() === null || ($path = $resolved->resolvedThrough()) === null) {
                continue;
            }

            $why = $this->whyNot($item instanceof SortInterface, $runsOn, new $model(), $path);
            if ($why !== null) {
                $problems[] = \sprintf(
                    'The JSON:API resource for type "%s" on server "%s" declares %s "%s"%s reaching through "%s", which the Eloquent provider cannot run: %s',
                    $type,
                    $serverName,
                    $item instanceof SortInterface ? 'the sort' : 'the filter',
                    $item instanceof SortInterface || $item instanceof FilterInterface ? $item->key() : '',
                    $owner !== null ? \sprintf(' of relation "%s"', $owner->name()) : '',
                    $path->path,
                    $why,
                );
            }
        }
    }

    /**
     * Why the Eloquent provider can't run `$path` from `$model`, or null when it can.
     */
    private function whyNot(bool $sort, string $parentType, Model $model, RelationPath $path): ?string
    {
        foreach ($path->hops as $hop) {
            $method = $hop->storage();
            $relation = \method_exists($model, $method) ? $model->{$method}() : null;
            if (!$relation instanceof Relation) {
                return \sprintf('"%s" is not a relation of %s.', $method, $model::class);
            }
            if ($sort && !($relation instanceof BelongsTo || $relation instanceof HasOne || $relation instanceof MorphOne || $relation instanceof HasOneThrough)) {
                return \sprintf('"%s" is not a to-one relation of %s, so a sort has no single row to read.', $method, $model::class);
            }

            $relatedType = $hop->relatedType();
            if ($this->providers->scopedRelatedProvider($parentType, $relatedType) !== null) {
                return \sprintf('the type "%s" is scoped by a provider other than Eloquent, whose scope an Eloquent query cannot apply to the rows it reaches.', $relatedType);
            }
            if ($this->modelFor($relatedType) === null) {
                return \sprintf('the relation "%s" reaches the type "%s", which no Eloquent model serves.', $hop->relation->name(), $relatedType);
            }

            $parentType = $relatedType;
            $model = $relation->getRelated();
        }

        $leafRelation = $path->leafRelation();
        if ($leafRelation !== null) {
            $method = $leafRelation->column() ?? $leafRelation->name();

            return \method_exists($model, $method) && $model->{$method}() instanceof Relation
                ? null
                : \sprintf('"%s" is not a relation of %s.', $method, $model::class);
        }

        $column = $path->leafColumn();
        if (\str_contains($column, '.') || \array_key_exists($column, $model->getCasts())) {
            return null;
        }

        try {
            $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
        } catch (\Throwable) {
            return null; // schema not introspectable at build time
        }

        return $columns === [] || \in_array($column, $columns, true)
            ? null
            : \sprintf('"%s" is not a column of %s\'s table "%s".', $column, $model::class, $model->getTable());
    }

    /**
     * @return class-string<Model>|null
     */
    private function modelFor(string $type): ?string
    {
        if (!$this->providers->supportsType($type)) {
            return null;
        }

        $provider = $this->providers->forType($type);

        return $provider instanceof EloquentDataProvider ? $provider->modelClassFor($type) : null;
    }

    /**
     * A resource's declared sorts and its default order's.
     *
     * @return list<SortInterface>
     */
    private static function sortsOf(AbstractResource $resource): array
    {
        return [...$resource->allSorts(), ...\array_map(static fn(SortDirective $directive): SortInterface => $directive->sort, $resource->defaultSort())];
    }

    /**
     * A relation's own sorts and its default order's.
     *
     * @return list<SortInterface>
     */
    private static function relationSorts(RelationInterface $relation): array
    {
        $defaults = $relation instanceof DefaultSortedRelationInterface ? $relation->defaultSort() : [];

        return [...$relation->sorts(), ...\array_map(static fn(SortDirective $directive): SortInterface => $directive->sort, $defaults)];
    }

    /**
     * Each monomorphic relation of `$resource` with the resource of its related type.
     *
     * @return list<array{RelationInterface, AbstractResource}>
     */
    private static function relatedResources(SerializerResolverInterface $server, AbstractResource $resource): array
    {
        $pairs = [];
        foreach ($resource->allFields() as $relation) {
            if (!$relation instanceof RelationInterface || \count($relation->relatedTypes()) !== 1) {
                continue;
            }

            $relatedType = $relation->relatedTypes()[0];
            $related = $server->hasSerializerFor($relatedType) ? $server->serializerFor($relatedType) : null;
            if ($related instanceof AbstractResource) {
                $pairs[] = [$relation, $related];
            }
        }

        return $pairs;
    }

    /**
     * Resolves every sort reaching through relations among `$sorts` against `$fields`.
     *
     * @param list<SortInterface>                                    $sorts
     * @param iterable<FieldInterface>                 $fields
     *
     * @throws \LogicException when a path cannot be resolved
     */
    private static function resolveSorts(ThroughPathResolver $resolver, array $sorts, iterable $fields): void
    {
        foreach ($sorts as $sort) {
            $resolver->resolveSort($sort, $fields);
        }
    }
}
