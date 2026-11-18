<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ForeignKeyGuard;

use haddowg\JsonApiLaravel\DataPersister\Eloquent\EloquentDataPersister;
use haddowg\JsonApiLaravel\DataProvider\DataProviderRegistry;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;
use Workbench\App\ForeignKey\FkItemResource;
use Workbench\App\ForeignKey\FkProfileResource;
use Workbench\App\ForeignKey\FkSecretResource;
use Workbench\App\ForeignKey\FkUserProvider;
use Workbench\App\Providers\ForeignKeyEloquentServiceProvider;

/**
 * Wires {@see $subject} as the provider-served `fk-users` beside the Eloquent `fk-items`,
 * `fk-profiles` and `fk-secrets` (whose model a global scope narrows) and the `fk-labels` a
 * provider without foreign-key reads serves, without routes, so a test can register them.
 */
final class ForeignKeyGuardServiceProvider extends ServiceProvider
{
    /**
     * @var class-string
     */
    public static string $subject = KeyedItemsUserResource::class;

    public function register(): void
    {
        JsonApi::register([self::$subject, FkItemResource::class, FkProfileResource::class, FkSecretResource::class, FkLabelResource::class])->ignoreRoutes();

        JsonApi::provider(new EloquentDataProvider(ForeignKeyEloquentServiceProvider::MODEL_BY_TYPE), priority: -128);
        JsonApi::persister(new EloquentDataPersister(ForeignKeyEloquentServiceProvider::MODEL_BY_TYPE), priority: -128);
        JsonApi::provider(new FkUserProvider(fn(): DataProviderRegistry => $this->app->make(DataProviderRegistry::class)));
        JsonApi::provider(new LabelProvider());
    }
}
