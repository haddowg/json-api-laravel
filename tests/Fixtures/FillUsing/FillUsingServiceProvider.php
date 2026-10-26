<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsing;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
final class FillUsingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([PinResource::class, BoardResource::class, CardResource::class, TagResource::class]);

        $models = ['pins' => Pin::class, 'boards' => Board::class, 'cards' => Card::class, 'tags' => Tag::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
    }
}
