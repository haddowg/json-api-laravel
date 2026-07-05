<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * The single source of truth for the music-catalog `jsonapi.*` configuration (decision 14)
 * — the exact Laravel translation of the Symfony example's
 * `config/packages/json_api.yaml`, so the projected OpenAPI document is byte-compatible
 * with the bundle's (decision 11). Both the byte-compat export and any `testbench serve`
 * wiring apply it, so the demo, the diff and the docs share one config.
 *
 * The `info` block and advertised base URIs are normalized away by the byte-compat diff,
 * but are set here anyway so the served/exported document is correct in its own right;
 * `servers`, `tags`, `security`, `max_include_depth` and `pagination.max_per_page` are
 * compared verbatim and MUST match the bundle cell-for-cell.
 */
final class CatalogConfig
{
    /**
     * The client-page-size cap — the single-source-of-truth value the bundle reads from
     * `SeedManifest::MAX_PER_PAGE` (set below core's default of 100 so the clamp is
     * witnessable, and identical on both sides so the `page[size]`/`page[limit]` parameter
     * schemas match).
     */
    public const int MAX_PER_PAGE = 50;

    public static function apply(Repository $config): void
    {
        $config->set('jsonapi.base_uri', 'https://music.example');
        $config->set('jsonapi.version', '1.1');
        // Include safeguard B: cap the `?include` nesting depth at 2 (the bundle's example
        // value; core's default is 3) so the include-parameter enums match.
        $config->set('jsonapi.max_include_depth', 2);
        $config->set('jsonapi.pagination.max_per_page', self::MAX_PER_PAGE);
        $config->set('jsonapi.atomic_operations.enabled', true);
        $config->set('jsonapi.atomic_operations.path', '/operations');
        $config->set('jsonapi.servers', self::servers());
        $config->set('jsonapi.openapi.info', self::info());
        $config->set('jsonapi.openapi.security', self::security());
        $config->set('jsonapi.openapi.tags', self::tags());
    }

    /**
     * The two servers: the implicit `default` (served at `/api`) plus a named `admin`
     * server (the minimal multi-server witness). The routing prefix/domain never reaches
     * the OpenAPI path keys (core keys paths by `uriType`), only the normalized
     * `servers[].url`, so their exact values do not affect the byte-compat diff.
     *
     * @return array<string, array{prefix: string, middleware: list<string>, domain: string|null}>
     */
    public static function servers(): array
    {
        return [
            'default' => ['prefix' => 'api', 'middleware' => [], 'domain' => null],
            'admin' => ['prefix' => 'admin', 'middleware' => [], 'domain' => null],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function info(): array
    {
        return [
            'title' => 'Music Catalog API',
            'version' => '1.0.0',
            'description' => 'A JSON:API music catalogue — albums, artists, tracks, playlists and libraries.',
            'contact' => ['name' => 'Catalog Team', 'url' => null, 'email' => 'api@music.example'],
            'license' => ['name' => 'MIT', 'identifier' => null, 'url' => null],
        ];
    }

    /**
     * A `bearer` scheme + the document-level default requirement — the operations a
     * policy/ability secures inherit this requirement (a `401`), matching the bundle's
     * security-expression projection.
     *
     * @return array<string, mixed>
     */
    public static function security(): array
    {
        return [
            'schemes' => [
                'bearer' => [
                    'type' => 'bearer',
                    'bearerFormat' => 'JWT',
                    'description' => 'A Bearer access token (the user identifier in this example).',
                ],
            ],
            'default_requirement' => [
                ['name' => 'bearer'],
            ],
        ];
    }

    /**
     * The authoritative top-level tag definitions (descriptions + emit order); a type
     * referencing an undefined tag falls back to its humanized-default tag.
     *
     * @return list<array<string, mixed>>
     */
    public static function tags(): array
    {
        return [
            ['name' => 'Catalog', 'description' => 'Albums and the album lifecycle actions (reissue, summarize, artwork upload).'],
            ['name' => 'Library', 'description' => "A user's saved playlists."],
        ];
    }
}
