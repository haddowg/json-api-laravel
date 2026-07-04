<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Facades;

use haddowg\JsonApiLaravel\JsonApiManager;
use Illuminate\Support\Facades\Facade;

/**
 * The static entry point to the {@see JsonApiManager} registration surface.
 *
 * Call these from a service provider's `register()`:
 *  - `JsonApi::discover([...paths])` — add directories to the discovery scan;
 *  - `JsonApi::register([...classes])` — register capability classes explicitly;
 *  - `JsonApi::provider($provider, $priority)` — register a data provider;
 *  - `JsonApi::persister($persister, $priority)` — register a data persister;
 *  - `JsonApi::constraintTranslator($translator)` — register a custom constraint translator;
 *  - `JsonApi::ignoreRoutes()` — opt out of automatic route registration.
 *
 * @method static JsonApiManager discover(string|list<string> $paths)
 * @method static JsonApiManager register(class-string|list<class-string> $classes)
 * @method static JsonApiManager provider(\haddowg\JsonApiLaravel\DataProvider\DataProviderInterface<object>|class-string<\haddowg\JsonApiLaravel\DataProvider\DataProviderInterface<object>> $provider, int $priority = 0)
 * @method static JsonApiManager persister(\haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface|class-string<\haddowg\JsonApiLaravel\DataPersister\DataPersisterInterface> $persister, int $priority = 0)
 * @method static JsonApiManager constraintTranslator(\haddowg\JsonApiLaravel\Validation\ConstraintTranslatorInterface|class-string<\haddowg\JsonApiLaravel\Validation\ConstraintTranslatorInterface> $translator)
 * @method static JsonApiManager ignoreRoutes()
 * @method static bool shouldRegisterRoutes()
 *
 * @see JsonApiManager
 */
final class JsonApi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return JsonApiManager::class;
    }
}
