<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Sparse\SparseWidget;
use Workbench\App\Sparse\SparseWidgetResource;

/**
 * The **in-memory** half of the sparse-by-default conformance wiring: the
 * {@see SparseWidgetResource} registered explicitly plus a single seeded, read-only
 * {@see InMemoryDataProvider} — no persister, because the witness only fetches (the
 * resource is `readOnly`). The seed mirrors the Eloquent half's row so identical
 * assertions referee both providers.
 */
final class SparseInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([SparseWidgetResource::class]);

        JsonApi::provider(new InMemoryDataProvider('sparseWidgets', [
            '1' => new SparseWidget(id: '1', name: 'Gadget', expensive_score: 99),
        ]));
    }
}
