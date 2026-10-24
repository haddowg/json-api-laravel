<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations;

use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Serializer\RelationshipLoadStateInterface;
use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataPersister\InMemoryDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentRelationshipLoadState;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the write-only relations fixture over the store named by `write_only.store`
 * (`eloquent` or `memory`).
 *
 * @internal
 */
final class WriteOnlyRelationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([BoxResource::class, KeeperResource::class, StickerResource::class]);

        if (config('write_only.store') === 'memory') {
            $this->registerInMemory();

            return;
        }

        $models = ['boxes' => Box::class, 'keepers' => Keeper::class, 'stickers' => Sticker::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
        $this->app->singleton(RelationshipLoadStateInterface::class, EloquentRelationshipLoadState::class);
    }

    private function registerInMemory(): void
    {
        $keepers = ['1' => new KeeperRecord('1', 'Ada'), '2' => new KeeperRecord('2', 'Grace')];
        $stickers = ['1' => new StickerRecord('1', 'fragile'), '2' => new StickerRecord('2', 'heavy')];
        $boxes = [
            '1' => new BoxRecord('1', 'North', $keepers['1'], $keepers['2'], [$stickers['1']], [$stickers['2']]),
            '2' => new BoxRecord('2', 'South', $keepers['2'], $keepers['1'], [$stickers['2']], [$stickers['1']]),
        ];

        $identify = static function (object $item): string {
            $id = Accessor::get($item, 'id');

            return \is_scalar($id) ? (string) $id : '';
        };
        $boxProvider = new InMemoryDataProvider('boxes', $boxes, identify: $identify);
        $keeperProvider = new InMemoryDataProvider('keepers', $keepers, identify: $identify);
        $stickerProvider = new InMemoryDataProvider('stickers', $stickers, identify: $identify);

        $resolve = static fn(string $type, string $id): ?object => match ($type) {
            'keepers' => $keeperProvider->store()->find($id),
            'stickers' => $stickerProvider->store()->find($id),
            default => null,
        };

        JsonApi::provider($boxProvider);
        JsonApi::provider($keeperProvider);
        JsonApi::provider($stickerProvider);
        JsonApi::persister(new InMemoryDataPersister('boxes', $boxProvider->store(), static fn(): BoxRecord => new BoxRecord(), $resolve));
    }
}
