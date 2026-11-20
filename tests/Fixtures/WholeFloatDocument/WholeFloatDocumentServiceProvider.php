<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WholeFloatDocument;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\Tests\Fixtures\DecimalColumn\Price;
use Illuminate\Support\ServiceProvider;

/**
 * @internal
 */
final class WholeFloatDocumentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([GaugeResource::class]);

        $models = ['gauges' => Price::class];
        JsonApi::provider(new EloquentDataProvider($models), priority: -128);
        JsonApi::persister(new EloquentDataPersister($models), priority: -128);
    }
}
