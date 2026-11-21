<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\ThroughPivot;

use haddowg\JsonApiLaravel\DataProvider\ScopedArrayFilterHandler;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Routing\RouteRegistrar;
use haddowg\JsonApiLaravel\Server\RelationOrderGuard;
use haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot\ThroughPivotServiceProvider;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * The in-memory provider holds no pivot rows, so every filter whose path reaches one is
 * unrecognised, a leading `pivot` on a relation's endpoints and a `pivot` mid-path alike, and
 * a sort through the pivot row is no sort it knows. A to-many ordered through its pivot row
 * by default fails route registration, since the order would do nothing.
 *
 * @internal
 */
#[CoversClass(ScopedArrayFilterHandler::class)]
#[CoversClass(RelationOrderGuard::class)]
final class InMemoryThroughPivotTest extends Orchestra
{
    private const string MEDIA_TYPE = 'application/vnd.api+json';

    private const string QUERIES = 'application/vnd.api+json; profile="https://haddowg.github.io/json-api/profiles/relationship-queries/"';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('through_pivot.store', 'memory');

        return [JsonApiServiceProvider::class, ThroughPivotServiceProvider::class];
    }

    #[Test]
    public function everyFilterReachingAPivotRowIsUnrecognised(): void
    {
        foreach (['filter[addedAt][min]=2026-02-01T00:00:00Z', 'filter[position]=1'] as $filter) {
            foreach (['/api/tpTracks/1/playlists', '/api/tpTracks/1/relationships/playlists'] as $uri) {
                $this->get($uri . '?' . $filter)->assertStatus(400)->assertJsonPath('errors.0.code', 'FILTERING_UNRECOGNIZED');
            }
            $this->get('/api/tpTracks/1?include=playlists&relatedQuery[playlists][' . \str_replace('filter[', 'filter][', $filter), self::QUERIES)->assertStatus(400);
        }

        $this->get('/api/tpAlbums?filter[addedAt][min]=2026-02-01T00:00:00Z')->assertStatus(400)->assertJsonPath('errors.0.code', 'FILTERING_UNRECOGNIZED');
        self::assertCount(2, (array) $this->get('/api/tpAlbums')->assertOk()->json('data'));
    }

    #[Test]
    public function aDeprecatedPivotTraversalIsUnrecognisedAsWell(): void
    {
        // Its through form can't be declared here, since a path past `pivot` fails warm-up.
        foreach (['filter[legacyAddedBy]=kim', 'filter[legacyUnaudited]=1'] as $filter) {
            $this->get('/api/tpTracks/1/playlists?' . $filter)->assertStatus(400)->assertJsonPath('errors.0.code', 'FILTERING_UNRECOGNIZED');
        }
    }

    #[Test]
    public function aMidPathPivotFilterDefaultIsUnrecognisedOnEveryRead(): void
    {
        $this->probe('default');

        $this->get('/api/tpAlbums')->assertStatus(400)->assertJsonPath('errors.0.code', 'FILTERING_UNRECOGNIZED');
    }

    #[Test]
    public function routeRegistrationRefusesAPathPastThePivotRow(): void
    {
        $this->probe('past_pivot');
        $router = $this->app?->make(Router::class);
        $registrar = $this->app?->make(RouteRegistrar::class);
        self::assertInstanceOf(Router::class, $router);
        self::assertInstanceOf(RouteRegistrar::class, $registrar);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not a relation the pivot rows of "playlists" hold');

        $registrar->registerServer($router);
    }

    #[Test]
    public function aSortThroughThePivotRowIsUnrecognised(): void
    {
        // The relation's only sort reads the pivot row, so it is left with none at all.
        $this->get('/api/tpTracks/1/playlists?sort=added')->assertStatus(400)->assertJsonPath('errors.0.code', 'SORTING_UNSUPPORTED');
        $this->get('/api/tpTracks/1/playlists')->assertOk();
    }

    #[Test]
    public function routeRegistrationRefusesADefaultOrderThroughThePivotRow(): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->app?->make('config');
        $config->set('through_pivot.memory_pivot_order', true);
        $router = $this->app?->make(Router::class);
        $registrar = $this->app?->make(RouteRegistrar::class);
        self::assertInstanceOf(Router::class, $router);
        self::assertInstanceOf(RouteRegistrar::class, $registrar);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Relation "cursorPlaylists" on resource type "tpTracks" is ordered by "added", which reaches through its pivot row, but the data provider of "tpPlaylists" does not query the relation\'s join');

        $registrar->registerServer($router);
    }

    private function probe(string $declaration): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $this->app?->make('config');
        $config->set('through_pivot.memory_probe', $declaration);
    }

    /**
     * @param array<string, string>|string $headers
     *
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    public function get($uri, $headers = self::MEDIA_TYPE)
    {
        $this->actingAs(new User(['id' => 3, 'name' => 'admin', 'is_admin' => true]));

        return $this->call('GET', $uri, [], [], [], ['HTTP_ACCEPT' => \is_string($headers) ? $headers : self::MEDIA_TYPE]);
    }
}
