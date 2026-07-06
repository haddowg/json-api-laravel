<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\ByteCompat;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * Exports the music-catalog standalone JSON-Schema documents (the `default` + `admin`
 * servers) to `build/laravel-schemas-<server>.json` — the Laravel half of the JSON-Schema
 * byte-compatibility check (PLAN decision 11), the sibling of
 * {@see MusicCatalogOpenApiExportTest}.
 *
 * The JSON-Schema export runs a DISTINCT projector path from the OpenAPI document (no
 * shared components — every type's relationships/links/meta are inline permissive objects),
 * so it can drift from the bundle independently of the OpenAPI document. The command with
 * no `--type`/`--output` emits a single object keyed by JSON:API type, which is exactly the
 * per-server artifact the diff compares. The document is a pure metadata projection (no
 * database), so the Eloquent wiring is registered purely for its resource/serializer
 * declarations.
 *
 * This runs in the normal suite as a guarantee that the full-domain schema document always
 * builds cleanly for every server on every matrix cell; the byte-compat diff itself (which
 * needs the sibling bundle checkout) lives in `composer byte-compat` and the `byte-compat`
 * CI job, both of which re-run this exporter to refresh the Laravel side.
 *
 * @internal
 */
#[CoversNothing]
final class MusicCatalogJsonSchemaExportTest extends Orchestra
{
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

    public function test_it_exports_the_json_schema_document_for_every_server(): void
    {
        $buildDir = \dirname(__DIR__, 2) . '/build';
        if (!\is_dir($buildDir)) {
            \mkdir($buildDir, 0o755, true);
        }

        foreach (['default', 'admin'] as $server) {
            $path = \sprintf('%s/laravel-schemas-%s.json', $buildDir, $server);

            // No --type / --output: the command emits every type as one object keyed by
            // type on stdout, captured here and written verbatim as the per-server artifact.
            $exitCode = Artisan::call('jsonapi:jsonschema:export', ['--server' => $server]);
            self::assertSame(0, $exitCode);

            $contents = Artisan::output();
            self::assertJson($contents);
            \file_put_contents($path, $contents);
            self::assertFileExists($path);

            /** @var array<string, mixed> $document */
            $document = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
            self::assertNotEmpty($document, "server '{$server}' exported no JSON Schema documents");
        }
    }
}
