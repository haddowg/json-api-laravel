<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelationOrderCheck\PivotOrderCheckServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A to-many ordered by a pivot field whose related type's provider doesn't query the join,
 * as on the in-memory provider, fails route registration and `jsonapi:optimize` rather than
 * reading every request in storage order.
 *
 * @internal
 */
final class RelationOrderPivotCheckTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private const string PROBLEM = 'Relation "entries" on resource type "racks" is ordered by the pivot field "rank", but the data provider of "entries" does not query the relation\'s join, so it cannot order by a pivot field.';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            PivotOrderCheckServiceProvider::class,
        ];
    }

    #[Test]
    public function routeRegistrationRefusesTheOrder(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(self::PROBLEM);

        $this->resolve(RouteRegistrar::class)->registerServer($this->resolve(Router::class));
    }

    #[Test]
    public function theWarmerReportsTheOrder(): void
    {
        self::assertStringContainsString(self::PROBLEM, \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm()));
    }

    #[Test]
    public function theWarmerReportsAJoinPosition(): void
    {
        self::assertStringContainsString(
            'Relation "slots" on resource type "bins" is ordered by the pivot field "position"',
            \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm()),
        );
    }
}
