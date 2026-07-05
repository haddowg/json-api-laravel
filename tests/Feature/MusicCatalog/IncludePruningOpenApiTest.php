<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\MusicCatalog;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * A focused regression for the {@see \haddowg\JsonApiLaravel\OpenApi\Metadata\IncludePathResolver}
 * cross-server include-pruning gate (its `relatedTypesSerializable` check): a relation whose
 * related type is not serializable on the server being documented contributes no `?include`
 * path, because advertising it would emit an include token the server can never fulfil
 * (the target renders links-only and hydrates nothing).
 *
 * The witness is the music-catalog `playlists` type on the **default** server: its `owner`
 * relation targets `users`, which is registered on the **admin** server only
 * (`#[AsJsonApiResource(server: 'admin')]`), while `tracks` targets the default-server
 * `tracks` type. So on the default document `owner` must be pruned and `tracks` must remain.
 *
 * @internal
 */
#[CoversNothing]
final class IncludePruningOpenApiTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    #[Test]
    #[Group('openapi')]
    public function it_prunes_an_admin_only_related_type_from_the_default_include_paths(): void
    {
        $enum = $this->includeEnum();

        // `owner` targets `users`, which is admin-server-only — not serializable on the
        // default server — so its include path is pruned.
        $this->assertNotContains('owner', $enum);

        // `tracks` targets the default-server `tracks` type, so it stays advertised.
        $this->assertContains('tracks', $enum);
    }

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

    /**
     * The `include` query-parameter enum on `GET /playlists` of the default server document.
     *
     * @return list<string>
     */
    private function includeEnum(): array
    {
        $doc = $this->resolve(DocumentFactory::class)->forServer('default')->toArray();
        \assert(\array_is_list($doc) === false);

        foreach ($this->arrayAt($doc, 'paths', '/playlists', 'get', 'parameters') as $parameter) {
            if (\is_array($parameter) && ($parameter['name'] ?? null) === 'include') {
                $enum = [];
                foreach ($this->arrayAt($parameter, 'schema', 'items', 'enum') as $token) {
                    if (\is_string($token)) {
                        $enum[] = $token;
                    }
                }

                return $enum;
            }
        }

        self::fail('The `include` parameter was not found on GET /playlists.');
    }
}
