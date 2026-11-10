<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Server;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\SerializerResolverInterface;

/**
 * Resolves the traversal path of every filter a resource declares, and of every filter its
 * relations scope to their related type, at route registration and `jsonapi:optimize`, so a
 * path ending on a polymorphic relation, or a relationship-ending `WhereThrough` that can't
 * compare an id, fails the boot rather than the first request. A filter family resolves per
 * request, so only what it returns is checked, when it is matched.
 */
final class FilterPathGuard
{
    /**
     * @throws \LogicException when a declared filter's path can't be resolved
     */
    public static function check(SerializerResolverInterface $resolver, AbstractResource $resource): void
    {
        $paths = ThroughPathResolver::forResolver($resolver);
        $fields = $resource->allFields();
        $paths->resolveAll($resource->allFilters(), $fields);

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
                $paths->resolveAll($relation->allFilters(), $related->allFields());
            }
        }
    }
}
