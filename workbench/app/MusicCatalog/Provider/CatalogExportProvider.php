<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Provider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use Workbench\App\MusicCatalog\Domain\CatalogExport;

/**
 * The read half of the resource-less `catalog-exports` type: a tiny custom provider over
 * a fixed list (there is no Eloquent model — the paired
 * {@see \Workbench\App\MusicCatalog\Provider\CatalogExportPersister} handles the async
 * create). Delegates to an {@see InMemoryDataProvider} so the shared window/criteria
 * machinery applies, and the SAME instance serves both provider arms — the seeded exports
 * mirror the bundle example so the two apps render identically.
 *
 * @extends AbstractDataProvider<object>
 */
final class CatalogExportProvider extends AbstractDataProvider
{
    private readonly InMemoryDataProvider $inner;

    public function __construct()
    {
        $this->inner = new InMemoryDataProvider('catalog-exports', [
            '1' => new CatalogExport(id: '1', format: 'json', status: 'ready'),
            '2' => new CatalogExport(id: '2', format: 'csv', status: 'ready'),
        ]);
    }

    public function supports(string $type): bool
    {
        return $type === 'catalog-exports';
    }

    public function fetchOne(string $type, string $id): ?object
    {
        return $this->inner->fetchOne($type, $id);
    }

    public function fetchCollection(string $type, CollectionCriteria $criteria): CollectionResult
    {
        return $this->inner->fetchCollection($type, $criteria);
    }
}
