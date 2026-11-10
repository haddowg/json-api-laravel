<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelationOrderCheck\RelationOrderCheckServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A to-many read order naming a sort its relation doesn't accept fails route registration
 * and `jsonapi:optimize`, in core's RelationOrderValidator wording, rather than every read
 * of the relation.
 *
 * @internal
 */
final class RelationOrderCheckTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private const string PROBLEM = 'Relation "entries" on resource type "ledgers" declares a defaultSort() on "postedAt", which is not a sort the relation accepts. It accepts: memo.';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            RelationOrderCheckServiceProvider::class,
        ];
    }

    #[Test]
    public function routeRegistrationRefusesTheOrder(): void
    {
        $router = $this->resolve(Router::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(self::PROBLEM);

        $this->resolve(RouteRegistrar::class)->registerServer($router);
    }

    #[Test]
    public function theWarmerReportsTheOrder(): void
    {
        self::assertContains(self::PROBLEM, $this->resolve(ServableResourceWarmer::class)->warm());
    }

    #[Test]
    public function optimizeFailsTheDeploy(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(1);
    }
}
