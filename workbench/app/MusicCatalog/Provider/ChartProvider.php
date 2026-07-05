<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Provider;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApiLaravel\DataProvider\AbstractDataProvider;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use Workbench\App\MusicCatalog\Domain\Chart;

/**
 * The data half of the resource-less `charts` type (capability composition): a tiny
 * custom provider returning a fixed list — there is no Eloquent model, so the
 * {@see \Workbench\App\MusicCatalog\Serializer\ChartSerializer} is paired with this
 * provider rather than the reference Eloquent provider. It proves a fetchable type needs
 * only a serializer (for the wire shape) + a provider (for the data), no
 * resource/hydrator/persister at all (PLAN decision 3, bundle ADR 0024).
 *
 * It delegates the reads to an {@see InMemoryDataProvider}, so `GET /charts` /
 * `GET /charts/{id}` reuse the shared {@see \haddowg\JsonApiLaravel\DataProvider\CriteriaApplier}
 * + array window — the same machinery the Eloquent and in-memory providers run, so a
 * non-DB source is a first-class collection. The relationship / batch / pivot seams use
 * the neutral defaults inherited from {@see AbstractDataProvider} (charts are reference
 * data, never the target of a relationship). The chart fixture mirrors the bundle example
 * so the two apps render the same chart.
 *
 * @extends AbstractDataProvider<object>
 */
final class ChartProvider extends AbstractDataProvider
{
    private readonly InMemoryDataProvider $inner;

    public function __construct()
    {
        $this->inner = new InMemoryDataProvider('charts', [
            '1' => new Chart(
                id: '1',
                name: 'Weekly Top',
                period: '2024-W03',
                entries: [
                    ['rank' => 1, 'trackId' => '2', 'plays' => 12000],
                    ['rank' => 2, 'trackId' => '1', 'plays' => 9800],
                    ['rank' => 3, 'trackId' => '4', 'plays' => 7100],
                ],
            ),
        ]);
    }

    public function supports(string $type): bool
    {
        return $type === 'charts';
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
