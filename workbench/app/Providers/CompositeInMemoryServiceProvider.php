<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Validation\CompositeWidget;
use Workbench\App\Validation\CompositeWidgetResource;

/**
 * The **in-memory** half of the composite-attribute conformance wiring: the
 * {@see CompositeWidgetResource} registered explicitly plus a writable, seeded
 * {@see InMemoryDataProvider} / {@see InMemoryDataPersister} pair sharing one store,
 * so a create is immediately readable and no database is touched. The seed mirrors the
 * Eloquent half's row so identical assertions referee both providers.
 */
final class CompositeInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([CompositeWidgetResource::class]);

        $composites = new InMemoryDataProvider('composites', $this->composites(), identify: self::identify(), assignId: self::assignId());
        JsonApi::provider($composites);
        JsonApi::persister(new InMemoryDataPersister('composites', $composites->store(), static fn(): CompositeWidget => new CompositeWidget()));
    }

    /**
     * @return array<int|string, CompositeWidget>
     */
    private function composites(): array
    {
        return [
            '1' => new CompositeWidget(id: '1', name: 'Seed', address: ['street' => '1 High St', 'city' => 'London', 'postcode' => 'EC1']),
        ];
    }

    private static function identify(): \Closure
    {
        return static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
    }

    private static function assignId(): \Closure
    {
        return static function (object $item, string $id): void {
            Accessor::set($item, 'id', $id);
        };
    }
}
