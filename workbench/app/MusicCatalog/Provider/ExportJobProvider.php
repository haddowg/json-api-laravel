<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Provider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use Workbench\App\MusicCatalog\Domain\ExportJob;

/**
 * The read half of the `export-jobs` type: a custom provider over a fixed list, seeded
 * with one `completed` job (whose fetch-one redirects `303` to its produced export) and
 * one `processing` job (whose fetch-one renders a normal `200`) — the two async-completion
 * outcomes the {@see \Workbench\App\MusicCatalog\JsonApi\ExportJobResource} declares. The
 * SAME instance serves both provider arms; the fixtures mirror the bundle example.
 *
 * @extends AbstractDataProvider<object>
 */
final class ExportJobProvider extends AbstractDataProvider
{
    private readonly InMemoryDataProvider $inner;

    public function __construct()
    {
        $this->inner = new InMemoryDataProvider('export-jobs', [
            'job-completed' => new ExportJob(id: 'job-completed', state: 'completed', exportId: '1'),
            'job-processing' => new ExportJob(id: 'job-processing', state: 'processing', exportId: ''),
        ]);
    }

    public function supports(string $type): bool
    {
        return $type === 'export-jobs';
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
