<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\FilterDefaultsOptOut\MisdeclaredFilterDefaultsServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A withoutFilterDefaults() key the related type doesn't default fails route registration
 * and `jsonapi:optimize`, in core's RelationFilterDefaultsValidator wording, rather than
 * passing silently.
 *
 * @internal
 */
final class RelationFilterDefaultsCheckTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private const string PROBLEM = 'Relation "books" on resource type "shelves" declares withoutFilterDefaults() on "title", which is not a filter declaring a default on its related type. Defaults are declared on: archived, draft.';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            MisdeclaredFilterDefaultsServiceProvider::class,
        ];
    }

    #[Test]
    public function routeRegistrationRefusesTheKey(): void
    {
        $router = $this->resolve(Router::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(self::PROBLEM);

        $this->resolve(RouteRegistrar::class)->registerServer($router);
    }

    #[Test]
    public function theWarmerReportsTheKey(): void
    {
        self::assertContains(self::PROBLEM, $this->resolve(ServableResourceWarmer::class)->warm());
    }

    #[Test]
    public function optimizeFailsTheDeploy(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->expectsOutputToContain(self::PROBLEM)->assertExitCode(1);
    }
}
