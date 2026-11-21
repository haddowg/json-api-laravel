<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ThroughRelations;

use haddowg\JsonApiLaravel\Facades\JsonApi;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Server\ThroughPathGuard;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThrAdvertiserResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThrBrokenResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThrCampaignResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThrLeadResource;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughRelations\ThrTemplateResource;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A sort declared with `->through()` whose path can't be resolved fails route registration, a
 * type's own sort and its default order alike, and `jsonapi:optimize` reports it too.
 *
 * @internal
 */
#[CoversClass(ThroughPathGuard::class)]
final class ThroughSortRegistrationTest extends Orchestra
{
    private const string PROBLEM = 'The JSON:API resource for type "thrBroken" on server "default" declares a sort that cannot run: Sort "templateName" reaches through "templates", which crosses a to-many relation';

    protected function tearDown(): void
    {
        parent::tearDown();
        ThrBrokenResource::$declaration = 'none';
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function declarations(): iterable
    {
        yield 'a declared sort' => ['toManySort'];
        yield 'a default order' => ['toManyDefault'];
    }

    #[Test]
    #[DataProvider('declarations')]
    public function routeRegistrationRefusesTheSort(string $declaration): void
    {
        ThrBrokenResource::$declaration = $declaration;
        $router = $this->resolve(Router::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(self::PROBLEM);

        $this->resolve(RouteRegistrar::class)->registerServer($router);
    }

    #[Test]
    #[DataProvider('declarations')]
    public function theWarmerReportsTheSort(string $declaration): void
    {
        ThrBrokenResource::$declaration = $declaration;
        $problems = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());

        self::assertStringContainsString(self::PROBLEM, $problems);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, BrokenSortServiceProvider::class];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function resolve(string $class): object
    {
        $instance = $this->app?->make($class);
        self::assertInstanceOf($class, $instance);

        return $instance;
    }
}

/**
 * @internal
 */
final class BrokenSortServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        JsonApi::register([ThrBrokenResource::class, ThrLeadResource::class, ThrCampaignResource::class, ThrAdvertiserResource::class, ThrTemplateResource::class]);
    }
}
