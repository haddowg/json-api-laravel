<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\RelationInterface;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\DataProviderInterface;
use haddowg\JsonApiLaravel\DataProvider\FetchesManyInterface;

/**
 * An application provider that owns ONE type and scopes it: a row whose `hidden` flag is set is
 * never served, by id, in a collection, or as a related member. It shadows the reference
 * provider for its type (registered at the default priority), the way an application adds a
 * visibility or tenancy scope to a type.
 *
 * It implements only the seams its own type is asked for and keeps the base class's empty batch
 * defaults, so any read that reaches its rows without going through it shows up as a leak.
 *
 * @extends AbstractDataProvider<object>
 */
final class HidesHiddenRows extends AbstractDataProvider implements FetchesManyInterface
{
    /**
     * @param DataProviderInterface<object> $inner
     */
    public function __construct(
        private readonly string $type,
        private readonly DataProviderInterface $inner,
    ) {}

    public function supports(string $type): bool
    {
        return $type === $this->type;
    }

    public function fetchOne(string $type, string $id): ?object
    {
        $item = $this->inner->fetchOne($type, $id);

        return $item !== null && !self::isHidden($item) ? $item : null;
    }

    public function fetchMany(string $type, array $ids): array
    {
        $rows = $this->inner instanceof FetchesManyInterface
            ? $this->inner->fetchMany($type, $ids)
            : \array_values(\array_filter(\array_map(fn(string $id): ?object => $this->inner->fetchOne($type, $id), $ids)));

        return \array_values(\array_filter($rows, static fn(object $row): bool => !self::isHidden($row)));
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return self::visible($this->inner->fetchCollection($type, $criteria));
    }

    public function fetchRelatedCollection(
        string $relatedType,
        object $parent,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): CollectionResult {
        return self::visible($this->inner->fetchRelatedCollection($relatedType, $parent, $relation, $criteria, $request));
    }

    public function relatedToOneMatches(
        string $relatedType,
        object $related,
        RelationInterface $relation,
        CollectionCriteria $criteria,
        JsonApiRequestInterface $request,
    ): bool {
        return !self::isHidden($related)
            && $this->inner->relatedToOneMatches($relatedType, $related, $relation, $criteria, $request);
    }

    public static function isHidden(object $item): bool
    {
        return (bool) Accessor::get($item, 'hidden');
    }

    /**
     * @param CollectionResult<object> $result
     *
     * @return CollectionResult<object>
     */
    private static function visible(CollectionResult $result): CollectionResult
    {
        $items = \is_array($result->items) ? $result->items : \iterator_to_array($result->items, false);
        $visible = \array_values(\array_filter($items, static fn(object $item): bool => !self::isHidden($item)));

        return new CollectionResult($visible, $result->total === null ? null : \count($visible));
    }
}
