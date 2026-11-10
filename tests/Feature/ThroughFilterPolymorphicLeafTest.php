<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughFilters\PolymorphicLeafServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Test;

/**
 * A filter path ending on a polymorphic relation, declared on a resource or scoped to a
 * relation, fails route registration and `jsonapi:optimize` rather than the first request.
 *
 * @internal
 */
final class ThroughFilterPolymorphicLeafTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    private const string RESOURCE_PROBLEM = 'Filter "subject" ends its path "subject" on the polymorphic relation "subject"';

    private const string RELATION_PROBLEM = 'Filter "noteSubject" ends its path "subject" on the polymorphic relation "subject"';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, PolymorphicLeafServiceProvider::class];
    }

    #[Test]
    public function routeRegistrationRefusesThePath(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('on the polymorphic relation "subject"');

        $this->resolve(RouteRegistrar::class)->registerServer($this->resolve(Router::class));
    }

    #[Test]
    public function theWarmerReportsBothPaths(): void
    {
        $problems = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());

        self::assertStringContainsString(self::RESOURCE_PROBLEM, $problems);
        self::assertStringContainsString(self::RELATION_PROBLEM, $problems);
    }
}
