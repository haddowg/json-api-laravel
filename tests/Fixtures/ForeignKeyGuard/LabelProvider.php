<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;

/**
 * Serves no `fk-labels`, and reads none through a foreign key.
 *
 * @extends AbstractDataProvider<object>
 */
final class LabelProvider extends AbstractDataProvider
{
    public function supports(string $type): bool
    {
        return $type === 'fk-labels';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return null;
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return new CollectionResult([]);
    }
}
