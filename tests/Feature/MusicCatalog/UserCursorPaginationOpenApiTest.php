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
 * so the admin-only `users` primary collection's single `page` deepObject parameter
 * (ADR 0130) carries the keyset object schema — the opaque `after`/`before` cursor tokens
 * plus `size`, and NOT the `number` of the page-based server default.
 *
 * The cursor projection is PER-RESOURCE, not server-wide: `albums` is exposed on the admin
 * server too and keeps the page-based `number`/`size` keys on the SAME document, so pinning a
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

        $this->assertContains('page', $this->parameterNames($doc, '/users', 'get'));
        $this->assertSame(['after', 'before', 'size'], $this->pageParameterPropertyKeys($doc, '/users'));

        // Per-resource, not server-wide: `albums` is shared onto the admin server and keeps
        // the page-based `number`/`size` keys on the SAME document.
        $this->assertSame(['number', 'size'], $this->pageParameterPropertyKeys($doc, '/albums'));
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
