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
 * Pins the OpenAPI projection of the catalogue's sole cursor (keyset) witness:
 * `UserResource::pagination()` returns a {@see \haddowg\JsonApi\Pagination\CursorPaginator},
 * so the admin-only `users` primary collection is documented with the keyset `page[…]`
 * vocabulary — the opaque `page[after]`/`page[before]` cursor tokens plus `page[size]`,
 * and NOT the `page[number]` of the page-based server default.
 *
 * The cursor projection is PER-RESOURCE, not server-wide: `albums` is exposed on the admin
 * server too and keeps the page-based `page[number]` on the SAME document, so pinning a
 * cursor on `users` left every other collection untouched. This is the shape the byte-for-byte
 * `composer byte-compat` diff against the Symfony bundle depends on.
 *
 * @internal
 */
#[CoversNothing]
final class UserCursorPaginationOpenApiTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    #[Test]
    #[Group('openapi')]
    public function the_cursor_paginated_users_collection_projects_the_keyset_page_vocabulary(): void
    {
        // `users` is admin-only, so the cursor surface rides the admin server's document.
        $doc = $this->resolve(DocumentFactory::class)->forServer('admin')->toArray();
        \assert(\array_is_list($doc) === false);

        $names = $this->parameterNames($doc, '/users', 'get');
        $this->assertContains('page[after]', $names);
        $this->assertContains('page[before]', $names);
        $this->assertContains('page[size]', $names);
        $this->assertNotContains('page[number]', $names, 'the cursor surface drops the page-based page[number]');

        // Per-resource, not server-wide: `albums` is shared onto the admin server and keeps
        // the page-based `page[number]` on the SAME document.
        $albumNames = $this->parameterNames($doc, '/albums', 'get');
        $this->assertContains('page[number]', $albumNames);
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
}
