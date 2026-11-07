<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\OperationErrors;

use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Facades\JsonApi;
use Illuminate\Support\ServiceProvider;

/**
 * Registers {@see LockerResource} and its {@see OpenLocker} action with an empty in-memory
 * provider. Neither described error is registered, so each is catalogued only through the
 * operations declared to raise it.
 *
 * @internal
 */
final class OperationErrorsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([LockerResource::class, OpenLocker::class]);
        JsonApi::provider(new InMemoryDataProvider('lockers', []));
    }
}
