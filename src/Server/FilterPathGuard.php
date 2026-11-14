<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\IdEncoderInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\Filter\TraversesPath;
use haddowg\JsonApi\Resource\Filter\WhereDoesntHave;
use haddowg\JsonApi\Resource\Filter\WhereGroup;
use haddowg\JsonApi\Resource\Filter\WhereHas;
use haddowg\JsonApi\Resource\Filter\WhereThrough;
use haddowg\JsonApi\Resource\Filter\WhereThroughIn;
use haddowg\JsonApi\Resource\SerializerResolverInterface;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\AmbiguousModelEncoder;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\BelongsToLeaf;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\ModelEncoders;
use haddowg\JsonApiLaravel\DataProvider\FilterPaths;
use haddowg\JsonApiLaravel\DataProvider\RelationCriteriaFactory;
use haddowg\JsonApiLaravel\Eloquent\ModelMapResolver;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Resolves the traversal path of every filter a resource declares, and of every filter its
 * relations scope to their related type, at route registration and `jsonapi:optimize`, so a
 * path ending on a polymorphic relation, or a relationship-ending `WhereThrough` that can't
 * compare an id, fails the boot rather than the first request. A path ending on a model that
 * several types serve with different id encoders, where no type the declaration names
 * serves it, fails too, since nothing says which encoder decodes its values. A filter family
 * resolves per request, so only what it returns is checked, when it is matched.
 */
final class FilterPathGuard
{
    /**
     * @param array<string, class-string<Model>>|null $modelByType the Eloquent model map, read from the container when not given
     *
     * @throws \LogicException when a declared filter's path can't be resolved, or its encoder can't be settled
     */
    public static function check(SerializerResolverInterface $resolver, AbstractResource $resource, ?array $modelByType = null): void
    {
        $paths = ThroughPathResolver::forResolver($resolver);
        $fields = $resource->allFields();
        $type = $resource::$type;
        // Read only once a filter needs it, so a resource without one never resolves the map.
        $map = static function () use (&$modelByType): array {
            return $modelByType ??= self::modelMap();
        };
        $encoders = static fn(): ModelEncoders => new ModelEncoders($map(), static fn(string $served): ?IdEncoderInterface => self::encoderOf($resolver, $served));

        self::guardEncoders($encoders, $map, $paths->resolveAll($resource->allFilters(), $fields), $type, $type, FilterPaths::namedTypes($fields, $type));

        foreach ($fields as $relation) {
            if (!$relation instanceof RelationInterface || $relation->allFilters() === []) {
                continue;
            }

            $relatedTypes = $relation->relatedTypes();
            if (\count($relatedTypes) !== 1 || !$resolver->hasSerializerFor($relatedTypes[0])) {
                continue;
            }

            $related = $resolver->serializerFor($relatedTypes[0]);
            if ($related instanceof AbstractResource) {
                self::refusePivotClash($resource, $relation, $related);
                $relatedFields = $related->allFields();
                self::guardEncoders(
                    $encoders,
                    $map,
                    $paths->resolveAll($relation->allFilters(), $relatedFields),
                    $type,
                    $relatedTypes[0],
                    FilterPaths::namedTypes($relatedFields, $relatedTypes[0], $type),
                );
            }
        }
    }

    /**
     * Refuses each traversal among `$filters`, groups searched, whose path ends past
     * `$model`'s relations on a `belongsTo` or its foreign key whose target model has no
     * settled encoder. A path core resolved to a declared relationship is named by it.
     *
     * @param \Closure(): ModelEncoders                       $encoders
     * @param \Closure(): array<string, class-string<Model>> $map
     * @param list<FilterInterface>                         $filters
     * @param string                                        $owner   the type whose filters these are, as messages name it
     * @param string                                        $runsOn  the type the filters run against, whose model the path starts from
     * @param list<string>                                  $named
     */
    private static function guardEncoders(\Closure $encoders, \Closure $map, array $filters, string $owner, string $runsOn, array $named): void
    {
        foreach ($filters as $filter) {
            if ($filter instanceof WhereGroup) {
                self::guardEncoders($encoders, $map, $filter->children, $owner, $runsOn, $named);

                continue;
            }

            if (!$filter instanceof TraversesPath || ($filter->resolvedPath()?->endsOnRelation() ?? false)) {
                continue;
            }

            $model = $map()[$runsOn] ?? null;
            if ($model === null) {
                continue;
            }

            $target = self::leafTarget(new $model(), \explode('.', $filter->throughPath()));
            if ($target === null) {
                continue;
            }

            $candidates = $encoders()->ambiguity($target, $named);
            if ($candidates !== null) {
                throw new \LogicException(AmbiguousModelEncoder::forFilter($owner, $filter->key(), $filter->throughPath(), $target, $candidates));
            }
        }
    }

    /**
     * The model the `belongsTo` at the end of `$segments` points at, walking `$model`'s
     * relation methods, or null when the path doesn't end on one.
     *
     * @param list<string> $segments
     *
     * @return class-string<Model>|null
     */
    private static function leafTarget(Model $model, array $segments): ?string
    {
        $leaf = \array_pop($segments);
        if ($leaf === null) {
            return null;
        }

        foreach ($segments as $segment) {
            if (!\method_exists($model, $segment)) {
                return null;
            }
            $relation = $model->{$segment}();
            if (!$relation instanceof Relation) {
                return null;
            }
            $model = $relation->getRelated();
        }

        $belongsTo = BelongsToLeaf::of($model, $leaf);

        return $belongsTo !== null ? $belongsTo->getRelated()::class : null;
    }

    private static function encoderOf(SerializerResolverInterface $resolver, string $type): ?IdEncoderInterface
    {
        if (!$resolver->hasSerializerFor($type)) {
            return null;
        }

        $resource = $resolver->serializerFor($type);
        if (!$resource instanceof AbstractResource) {
            return null;
        }

        foreach ($resource->allFields() as $field) {
            if ($field instanceof Id) {
                return $field->encoder();
            }
        }

        return null;
    }

    /**
     * @return array<string, class-string<Model>>
     */
    private static function modelMap(): array
    {
        $container = Container::getInstance();

        return $container->bound(ModelMapResolver::class) ? $container->make(ModelMapResolver::class)->map() : [];
    }

    /**
     * Refuses a relation filter whose relationship or path starts at the pivot row when
     * the related type declares a relation stored as `pivot`, since `pivot.` would then name
     * either the row or that relation.
     *
     * @throws \LogicException naming the type, relation and filter
     */
    private static function refusePivotClash(AbstractResource $resource, RelationInterface $relation, AbstractResource $related): void
    {
        $clash = null;
        foreach ($related->allFields() as $field) {
            if ($field instanceof RelationInterface && $field->column() === 'pivot') {
                $clash = $field;

                break;
            }
        }
        if ($clash === null) {
            return;
        }

        foreach ($relation->allFilters() as $filter) {
            $target = match (true) {
                $filter instanceof WhereHas, $filter instanceof WhereDoesntHave => $filter->relationship,
                $filter instanceof WhereThrough, $filter instanceof WhereThroughIn => $filter->path,
                default => '',
            };
            if (!\str_starts_with($target, RelationCriteriaFactory::PIVOT_PREFIX)) {
                continue;
            }

            throw new \LogicException(\sprintf(
                'Relation "%s" of type "%s" declares filter "%s" on "%s", which starts at the pivot row, but its related type "%s" has a relation "%s" stored as "pivot", so the "pivot." prefix is ambiguous. Store that relation under another name.',
                $relation->name(),
                $resource::$type,
                $filter->key(),
                $target,
                $related::$type,
                $clash->name(),
            ));
        }
    }
}
