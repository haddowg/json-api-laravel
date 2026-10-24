<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\OpenApiServe;

use haddowg\JsonApi\OpenApi\Serve\EntityTag;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\OpenApi\JsonSchemaFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\FeatureGateDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\FeatureGateSchemaDecorator;
use haddowg\JsonApiLaravel\Tests\Fixtures\OpenApiServe\HostServersDecorator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Serve decorators that all supply a cache key (ADR 0036): the document varies per host,
 * orphaned components are pruned, and a matching `If-None-Match` is answered before any
 * decorator runs.
 *
 * @internal
 */
final class OpenApiServeKeyedTest extends OpenApiServeTestCase
{
    protected static function serveDecorators(): array
    {
        return [
            'openapi' => [HostServersDecorator::class, FeatureGateDecorator::class],
            'schemas' => [FeatureGateSchemaDecorator::class],
        ];
    }

    #[Test]
    #[Group('openapi')]
    public function the_servers_follow_the_request_host(): void
    {
        $this->assertSame([['url' => 'https://one.test/api']], $this->document($this->fetch('/docs.json', 'one.test'))['servers'] ?? null);
        $this->assertSame([['url' => 'https://two.test/api']], $this->document($this->fetch('/docs.json', 'two.test'))['servers'] ?? null);
    }

    #[Test]
    #[Group('openapi')]
    public function operations_outside_the_tenants_features_are_removed_and_what_they_alone_used_is_pruned(): void
    {
        $this->warm();
        $full = $this->fetch('/docs.json', 'full.tenant.test');
        $limited = $this->fetch('/docs.json', 'tenant.test');
        $this->assertValidOpenApi($full);
        $this->assertValidOpenApi($limited);

        $fullDoc = $this->document($full);
        $limitedDoc = $this->document($limited);

        $fullPaths = $this->arr($fullDoc['paths'] ?? null);
        $limitedPaths = $this->arr($limitedDoc['paths'] ?? null);
        $this->assertArrayHasKey('/genres', $fullPaths);
        $this->assertArrayHasKey('post', $this->arr($fullPaths['/albums']));

        // The genres endpoints are gone, path items and all, and so is the album create.
        $this->assertArrayNotHasKey('/genres', $limitedPaths);
        $this->assertArrayNotHasKey('/genres/{id}', $limitedPaths);
        $this->assertArrayNotHasKey('post', $this->arr($limitedPaths['/albums']));
        $this->assertArrayHasKey('get', $this->arr($limitedPaths['/albums']));

        $fullSchemas = $this->arr($this->arr($fullDoc['components'] ?? null)['schemas'] ?? null);
        $limitedSchemas = $this->arr($this->arr($limitedDoc['components'] ?? null)['schemas'] ?? null);

        // Only the removed operations used these.
        foreach (['GenresResource', 'GenresCollection', 'GenresDocument', 'GenresCreateRequest', 'GenresCreateAttributes', 'GenresUpdateRequest', 'GenresUpdateAttributes', 'AlbumsCreateRequest', 'AlbumsCreateAttributes'] as $name) {
            $this->assertArrayHasKey($name, $fullSchemas, $name);
            $this->assertArrayNotHasKey($name, $limitedSchemas, $name);
        }

        // The artist endpoints still return albums, and albums link their artist.
        foreach (['AlbumsResource', 'AlbumsCollection', 'AlbumsUpdateRequest', 'ArtistsResource', 'Meta', 'Links', 'ErrorDocument'] as $name) {
            $this->assertArrayHasKey($name, $limitedSchemas, $name);
        }

        // The tag only genres operations used goes; the configured one no operation uses stays.
        $tagNames = fn(array $doc): array => \array_map(
            fn(mixed $tag): mixed => $this->arr($tag)['name'] ?? null,
            $this->arr($doc['tags'] ?? []),
        );
        $this->assertContains('Genres', $tagNames($fullDoc));
        $this->assertNotContains('Genres', $tagNames($limitedDoc));
        $this->assertContains('Albums', $tagNames($limitedDoc));
        $this->assertContains('Catalog', $tagNames($limitedDoc));
    }

    #[Test]
    #[Group('openapi')]
    public function the_etag_hashes_the_artifact_hash_and_every_cache_key(): void
    {
        $this->warm();
        $artifactHash = $this->store()->readHash('default');
        $this->assertIsString($artifactHash);

        $response = $this->fetch('/docs.json', 'one.test');

        // Keys are hashed in the order the decorators run: registration order.
        $keys = \json_encode([
            [HostServersDecorator::class, 'one.test'],
            [FeatureGateDecorator::class, ['v' => 1, 'features' => ['catalog']]],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $this->assertSame(self::quoted(EntityTag::hash($artifactHash . "\n" . $keys)), $this->etagOf($response));
        $this->assertNotSame(self::quoted(EntityTag::hash((string) $response->getContent())), $this->etagOf($response));

        $this->assertNotSame($this->etagOf($response), $this->etagOf($this->fetch('/docs.json', 'two.test')));
        $this->assertSame($this->etagOf($response), $this->etagOf($this->fetch('/docs.json', 'one.test')));
    }

    #[Test]
    #[Group('openapi')]
    public function a_matching_if_none_match_is_answered_without_decorating(): void
    {
        $this->warm();
        $etag = $this->etagOf($this->fetch('/docs.json', 'one.test'));
        $this->assertSame(1, FeatureGateDecorator::$decorated);

        foreach ([$etag, 'W/' . $etag] as $ifNoneMatch) {
            $response = $this->fetch('/docs.json', 'one.test', ['If-None-Match' => $ifNoneMatch]);
            $response->assertStatus(304);
            $this->assertSame($etag, $this->etagOf($response));
            $this->assertSame(self::CACHE_CONTROL, $this->cacheControl($response));
        }

        $this->assertSame(1, FeatureGateDecorator::$decorated);
        $this->assertSame(3, FeatureGateDecorator::$keyed);

        // Another host's ETag does not match.
        $this->fetch('/docs.json', 'two.test', ['If-None-Match' => $etag])->assertOk();
        $this->assertSame(2, FeatureGateDecorator::$decorated);
    }

    #[Test]
    #[Group('openapi')]
    public function an_unwarmed_cache_still_serves_the_decorated_document(): void
    {
        $before = $this->fetch('/docs.json', 'one.test');
        $this->warm();
        $after = $this->fetch('/docs.json', 'one.test');

        $this->assertSame($before->getContent(), $after->getContent());
        $this->assertSame($this->etagOf($before), $this->etagOf($after));
    }

    #[Test]
    #[Group('openapi')]
    public function the_cli_exports_and_static_files_are_not_decorated(): void
    {
        $file = $this->cacheDir . '-export.json';
        $this->jsonApiArtisan('jsonapi:openapi:export', ['--output' => $file])->assertExitCode(0);
        $raw = \file_get_contents($file);
        @\unlink($file);
        $this->assertIsString($raw);

        $exported = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($exported);
        $this->assertArrayHasKey('/genres', $this->arr($exported['paths'] ?? null));
        $this->assertArrayHasKey('GenresCreateRequest', $this->arr($this->arr($exported['components'] ?? null)['schemas'] ?? null));
        $this->assertSame($this->resolve(DocumentFactory::class)->forServer()->toArray(), $exported);

        $schemaDir = $this->cacheDir . '-schemas';
        $this->jsonApiArtisan('jsonapi:jsonschema:export', ['--output' => $schemaDir])->assertExitCode(0);
        $this->assertFileExists($schemaDir . '/genres.json');
        foreach (\glob($schemaDir . '/*') ?: [] as $exportedSchema) {
            @\unlink($exportedSchema);
        }
        @\rmdir($schemaDir);

        $this->warm();
        $static = \file_get_contents($this->publicDir . '/default.json');
        $this->assertSame($this->resolve(DocumentFactory::class)->forServer()->toJsonString(true), $static);
        $staticSchemas = \file_get_contents($this->publicDir . '/default.schemas.json');
        $this->assertIsString($staticSchemas);
        $this->assertArrayHasKey('genres', $this->arr(\json_decode($staticSchemas, true)));

        $this->assertSame(0, FeatureGateDecorator::$decorated + FeatureGateSchemaDecorator::$decorated);
    }

    #[Test]
    #[Group('openapi')]
    public function the_schemas_aggregate_is_decorated_and_answered_conditionally_the_same_way(): void
    {
        $this->warm();
        $artifactHash = $this->store()->readSchemaAggregateHash('default');
        $this->assertIsString($artifactHash);

        $full = $this->fetch('/schemas.json', 'full.tenant.test');
        $limited = $this->fetch('/schemas.json', 'tenant.test');
        $this->assertArrayHasKey('genres', $this->document($full));
        $this->assertArrayNotHasKey('genres', $this->document($limited));
        $this->assertArrayHasKey('albums', $this->document($limited));
        $this->assertSame(2, FeatureGateSchemaDecorator::$decorated);

        $keys = \json_encode([[FeatureGateSchemaDecorator::class, ['v' => 1, 'features' => ['catalog']]]], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        $this->assertSame(self::quoted(EntityTag::hash($artifactHash . "\n" . $keys)), $this->etagOf($limited));

        // A full tenant's aggregate is the warmed one, byte for byte.
        $this->assertSame($this->store()->readSchemaAggregate('default'), $full->getContent());
        $this->assertSame(
            \json_decode((string) \json_encode((object) $this->resolve(JsonSchemaFactory::class)->forServer()), true),
            $this->document($full),
        );

        $this->fetch('/schemas.json', 'tenant.test', ['If-None-Match' => 'W/' . $this->etagOf($limited)])->assertStatus(304);
        $this->assertSame(2, FeatureGateSchemaDecorator::$decorated);
    }
}
