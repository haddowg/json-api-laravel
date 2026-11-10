<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Filter\FilterInterface;
use haddowg\JsonApi\Resource\Filter\ThroughPathResolver;
use haddowg\JsonApi\Resource\SerializerResolverInterface;

/**
 * Resolves a matched filter's traversal path against the fields of the type the filters run
 * against, through core's {@see ThroughPathResolver}, so a `WhereThrough` or `WhereThroughIn`
 * whose path ends on a relationship compares the related resource's decoded id and validates
 * against its format. It rides a {@see CollectionCriteria}, and the applier and the filter
 * value validator resolve each filter after matching it, a family's included.
 */
final readonly class FilterPaths
{
    /**
     * @param list<FieldInterface> $fields the inventory of the type the filters run against
     */
    public function __construct(
        private ThroughPathResolver $resolver,
        private array $fields,
    ) {}

    /**
     * The paths of `$resource`'s filters, or of a relation's filters when `$resource` is its
     * related type. `null` when there is no single type to resolve against.
     */
    public static function of(SerializerResolverInterface $server, ?AbstractResource $resource): ?self
    {
        return $resource === null ? null : new self(ThroughPathResolver::forResolver($server), $resource->allFields());
    }

    /**
     * @throws \LogicException when the path ends on a polymorphic relation, or the filter cannot compare what it ends on
     */
    public function resolve(FilterInterface $filter): FilterInterface
    {
        return $this->resolver->resolve($filter, $this->fields);
    }
}
