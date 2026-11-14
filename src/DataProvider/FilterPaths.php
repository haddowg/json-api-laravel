<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\DataProvider;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\FieldInterface;
use haddowg\JsonApi\Resource\Field\RelationInterface;
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
     * @param string|null          $type   the type the filters run against, when known
     * @param list<string>         $named  every type the declaration names: the type itself, the related types of its relations, and the parent type of a relation whose filters these are
     */
    public function __construct(
        private ThroughPathResolver $resolver,
        private array $fields,
        public ?string $type = null,
        public array $named = [],
    ) {}

    /**
     * The paths of `$resource`'s filters, or of a relation's filters when `$resource` is its
     * related type. `null` when there is no single type to resolve against.
     */
    public static function of(SerializerResolverInterface $server, ?AbstractResource $resource, ?string $parentType = null): ?self
    {
        if ($resource === null) {
            return null;
        }

        $type = $resource::$type !== '' ? $resource::$type : null;

        return new self(ThroughPathResolver::forResolver($server), $resource->allFields(), $type, self::namedTypes($resource->allFields(), $type, $parentType));
    }

    /**
     * The types a declaration names: `$type`, the related types of the relations among
     * `$fields`, and `$parentType`, the type that declares the relation whose filters these
     * are. A filter reaching a model several types serve decodes with the encoder these name.
     *
     * @param iterable<FieldInterface> $fields
     *
     * @return list<string>
     */
    public static function namedTypes(iterable $fields, ?string $type, ?string $parentType = null): array
    {
        $named = $type !== null ? [$type] : [];
        foreach ($fields as $field) {
            if ($field instanceof RelationInterface) {
                foreach ($field->relatedTypes() as $related) {
                    $named[] = $related;
                }
            }
        }
        if ($parentType !== null) {
            $named[] = $parentType;
        }

        return \array_values(\array_unique($named));
    }

    /**
     * @throws \LogicException when the path ends on a polymorphic relation, or the filter cannot compare what it ends on
     */
    public function resolve(FilterInterface $filter): FilterInterface
    {
        return $this->resolver->resolve($filter, $this->fields);
    }
}
