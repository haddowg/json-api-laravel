<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\SingularNames\CategoryResource;
use Workbench\App\SingularNames\Item;
use Workbench\App\SingularNames\ProductResource;
use Workbench\App\SingularNames\ShelfResource;
use Workbench\App\SingularNames\TagResource;

/**
 * Registers the singular-noun witnesses: {@see ProductResource} names its singular on the
 * attribute, {@see CategoryResource} on both the attribute and its hook, and
 * {@see ShelfResource} on neither.
 */
final class SingularNamesInMemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ProductResource::class, CategoryResource::class, ShelfResource::class, TagResource::class]);

        foreach (['products', 'categories', 'shelves', 'tags'] as $type) {
            $provider = new InMemoryDataProvider($type, [], identify: self::identify());
            JsonApi::provider($provider);
            JsonApi::persister(new InMemoryDataPersister($type, $provider->store(), static fn(): Item => new Item()));
        }
    }

    private static function identify(): \Closure
    {
        return static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
    }
}
