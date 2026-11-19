<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ThroughPivot;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Server\ThroughPathGuard;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot\ThroughPivotServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot\TpBrokenResource;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * A `pivot` segment the Eloquent provider can't run is refused at `jsonapi:optimize`, and one
 * core can't resolve fails route registration too, each naming the type and the declaration.
 *
 * @internal
 */
#[CoversClass(ThroughPathGuard::class)]
final class EloquentThroughPivotWarmUpTest extends Orchestra
{
    protected function tearDown(): void
    {
        parent::tearDown();
        TpBrokenResource::$declaration = 'none';
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusals(): iterable
    {
        yield 'a leading pivot on a collection' => ['leading-pivot-on-collection', 'runs under none'];
        yield 'a leading pivot on a relation with no pivot rows' => ['plain-relation-pivot', 'which has no pivot rows'];
        yield 'a pivot after a relation with no pivot rows' => ['pivot-after-plain-to-many', 'which has no pivot rows'];
        yield 'a relation the pivot row does not hold' => ['unknown-pivot-row-relation', 'is not a relation the pivot rows of "playlists" hold'];
        yield 'a sort through a mid-path pivot' => ['mid-path-pivot-sort', 'whose `pivot` follows a to-many relation'];
        yield 'a pivot row relation no type serves' => ['pivot-row-relation-no-type', 'which no registered type serves'];
        yield 'a leaf that is no column of the pivot table' => ['leaf-not-pivot-column', 'the filter "x" of relation "listedTracks" reaching through "pivot", which the Eloquent provider cannot run: "missing" is not a column of the pivot table "tp_album_track"'];
        yield 'a morphTo on the pivot model' => ['pivot-row-morph-to', 'The pivot rows of relation "playlists" hold "subject", a morphTo, which reaches no single type'];
        yield 'a pivot model without stored pivot fields' => ['association-class-without-stored-fields', 'the filter "x" reaching through "entryTracks.pivot.addedBy", which the Eloquent provider cannot run: the Eloquent provider reads the pivot rows of a BelongsToMany with stored pivot fields, and "entryTracks" is not one.'];
        yield 'the pivot rows of a morphToMany' => ['morph-to-many-pivot', 'the filter "x" of relation "taggedTracks" reaching through "pivot", which the Eloquent provider cannot run: "taggedTracks" is a MorphToMany'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function theWarmerRefusesAPivotTheProviderCannotRun(string $declaration, string $message): void
    {
        TpBrokenResource::$declaration = $declaration;

        $warmer = $this->app?->make(ServableResourceWarmer::class);
        self::assertInstanceOf(ServableResourceWarmer::class, $warmer);

        self::assertStringContainsString($message, \implode("\n", $warmer->warm()));
    }

    #[Test]
    public function routeRegistrationRefusesAMorphToOnThePivotModel(): void
    {
        TpBrokenResource::$declaration = 'pivot-row-morph-to';
        $router = $this->app?->make(Router::class);
        $registrar = $this->app?->make(RouteRegistrar::class);
        self::assertInstanceOf(Router::class, $router);
        self::assertInstanceOf(RouteRegistrar::class, $registrar);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('hold "subject", a morphTo, which reaches no single type');

        $registrar->registerServer($router);
    }

    #[Test]
    public function theBootRefusesAPivotRowRelationSeveralTypesServe(): void
    {
        // The provider's model map is read when it registers, so the app boots again.
        TpBrokenResource::$declaration = 'pivot-row-relation-several-types';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('which several types serve (tpUsers, tpUserAliases)');

        $this->refreshApplication();
    }

    #[Test]
    public function routeRegistrationRefusesAPivotCoreCannotResolve(): void
    {
        TpBrokenResource::$declaration = 'unknown-pivot-row-relation';
        $router = $this->app?->make(Router::class);
        $registrar = $this->app?->make(RouteRegistrar::class);
        self::assertInstanceOf(Router::class, $router);
        self::assertInstanceOf(RouteRegistrar::class, $registrar);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not a relation the pivot rows of "playlists" hold');

        $registrar->registerServer($router);
    }

    #[Test]
    public function theWarmerAcceptsTheWorkingDeclarations(): void
    {
        $warmer = $this->app?->make(ServableResourceWarmer::class);
        self::assertInstanceOf(ServableResourceWarmer::class, $warmer);

        self::assertSame([], \array_values(\array_filter($warmer->warm(), static fn(string $problem): bool => \str_contains($problem, '"tp'))));
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, ThroughPivotServiceProvider::class];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        ThroughPivotServiceProvider::migrate();
    }
}
