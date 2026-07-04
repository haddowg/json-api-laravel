<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * The base for the package's feature tests: it boots a real Testbench Laravel app with
 * the package service provider and the workbench provider (which seeds the in-memory
 * providers and points discovery at `workbench/app/JsonApi`), so an HTTP request drives
 * the full negotiate → dispatch → render path through the invokable controller.
 *
 * @internal
 */
abstract class TestCase extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            WorkbenchServiceProvider::class,
        ];
    }
}
