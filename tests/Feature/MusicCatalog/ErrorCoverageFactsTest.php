<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApi\OpenApi\Metadata\ActionScope;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDetachmentRefusal;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresDocumentValidation;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresIncludeSafeguards;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresLinkageResolution;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresMaxIncludeDepth;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresOperationSecurity;
use haddowg\JsonApi\OpenApi\Metadata\DeclaresStrictQueryParameters;
use haddowg\JsonApi\OpenApi\Metadata\OperationKey;
use haddowg\JsonApi\OpenApi\Metadata\OperationKind;
use haddowg\JsonApi\OpenApi\Metadata\RelationMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\ServerMetadataInterface;
use haddowg\JsonApi\OpenApi\Metadata\TypeMetadataInterface;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\Metadata\MetadataSource;
use haddowg\JsonApiLaravel\Tests\Support\PinsCatalogClock;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * The runtime facts the catalogue's metadata reports, which decide the error codes each
 * operation documents. The bundle example's twin asserts the same answers.
 */
#[CoversNothing]
#[Group('spec:openapi')]
final class ErrorCoverageFactsTest extends Orchestra
{
    use PinsCatalogClock;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            MusicCatalogEloquentServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        CatalogConfig::apply($config);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__, 3) . '/workbench/database/migrations');
    }

    #[Test]
    public function onlyAGatedOperationCanDenyAccess(): void
    {
        $server = $this->server();
        self::assertInstanceOf(DeclaresOperationSecurity::class, $server);

        $playlists = $this->type($server, 'playlists');
        self::assertTrue($server->canDenyAccess($playlists, OperationKey::crud(OperationKind::Update)));
        self::assertTrue($server->canDenyAccess($playlists, OperationKey::crud(OperationKind::Delete)));
        self::assertFalse($server->canDenyAccess($playlists, OperationKey::crud(OperationKind::FetchOne)));
        // The relation's own read ability guards its relationship read.
        self::assertTrue($server->canDenyAccess($playlists, OperationKey::relation(OperationKind::FetchRelationship, 'owner')));

        $artists = $this->type($server, 'artists');
        self::assertFalse($server->canDenyAccess($artists, OperationKey::crud(OperationKind::FetchCollection)));

        $albums = $this->type($server, 'albums');
        self::assertTrue($server->canDenyAccess($albums, OperationKey::action('reissue', ActionScope::Resource, 'POST')));
    }

    #[Test]
    public function theRouteMiddlewareCanRequireAuthenticationOnEveryRoute(): void
    {
        $server = $this->server();
        self::assertInstanceOf(DeclaresOperationSecurity::class, $server);

        // A malformed bearer token is refused before the package runs, even on a public read.
        self::assertTrue($server->canRequireAuthentication($this->type($server, 'artists'), OperationKey::crud(OperationKind::FetchCollection)));
        self::assertTrue($server->canRequireAuthentication(null, OperationKey::atomic()));
        self::assertFalse($server->canDenyAccess(null, OperationKey::atomic()));

        // A policy refusal is a 403 whoever calls; the 401 here comes from the middleware.
        self::assertTrue($server->canRequireAuthentication($this->type($server, 'playlists'), OperationKey::crud(OperationKind::Delete)));
    }

    #[Test]
    public function aMalformedBearerTokenIs401(): void
    {
        $response = $this->get('/api/artists', ['Accept' => 'application/vnd.api+json', 'Authorization' => 'Basic YWRhOg==']);

        $response->assertStatus(401);
        self::assertSame('AUTHENTICATION_REQUIRED', $response->json('errors.0.code'));
    }

    #[Test]
    public function theServerReportsItsQueryAndDocumentChecks(): void
    {
        $server = $this->server();
        self::assertInstanceOf(DeclaresStrictQueryParameters::class, $server);
        self::assertInstanceOf(DeclaresDocumentValidation::class, $server);
        self::assertInstanceOf(DeclaresMaxIncludeDepth::class, $server);

        self::assertTrue($server->strictQueryParameters());
        self::assertFalse($server->validatesRequestDocuments());
        self::assertTrue($server->checksResourceObjectMembers());
        self::assertFalse($server->validatesResponseDocuments());
        self::assertSame(2, $server->maxIncludeDepth());
    }

    #[Test]
    public function aTypeReportsTheIncludeSafeguardsOfItsSerializer(): void
    {
        $albums = $this->type($this->server(), 'albums');
        self::assertInstanceOf(DeclaresIncludeSafeguards::class, $albums);

        self::assertNull($albums->maxIncludeDepth());
        self::assertSame([], $albums->excludedIncludePaths());
    }

    #[Test]
    public function aRelationReportsWhatTheSchemaRefuses(): void
    {
        $tracks = $this->relation($this->type($this->server(), 'albums'), 'tracks');
        self::assertInstanceOf(DeclaresDetachmentRefusal::class, $tracks);
        self::assertInstanceOf(DeclaresLinkageResolution::class, $tracks);

        // A track's album_id is nullable, so a track can leave its album.
        self::assertFalse($tracks->canRefuseDetachment());
        // An integer id that isn't one of the related type's is a 404.
        self::assertTrue($tracks->canMissLinkage());
    }

    private function server(): ServerMetadataInterface
    {
        return $this->app?->make(MetadataSource::class)->forServer() ?? self::fail('No application.');
    }

    private function type(ServerMetadataInterface $server, string $name): TypeMetadataInterface
    {
        foreach ($server->types() as $type) {
            if ($type->type() === $name) {
                return $type;
            }
        }

        self::fail(\sprintf('The server describes no "%s" type.', $name));
    }

    private function relation(TypeMetadataInterface $type, string $name): RelationMetadataInterface
    {
        foreach ($type->relations() as $relation) {
            if ($relation->name() === $name) {
                return $relation;
            }
        }

        self::fail(\sprintf('The "%s" type has no "%s" relation.', $type->type(), $name));
    }
}
