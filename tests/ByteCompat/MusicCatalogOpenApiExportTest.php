<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\ByteCompat;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\PinsCatalogClock;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogEloquentServiceProvider;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * Exports the music-catalog OpenAPI documents (the `default` + `admin` servers) to
 * `build/laravel-<server>.json` — the Laravel half of the byte-compatibility check.
 * The document is a pure metadata projection (no database), so the
 * Eloquent wiring is registered purely for its resource/action/serializer declarations.
 *
 * This runs in the normal suite as a guarantee that the full-domain document always
 * builds cleanly for every server on every matrix cell; the byte-compat diff itself
 * (which needs the sibling bundle checkout) lives in `composer byte-compat` and the
 * `byte-compat` CI job, both of which re-run this exporter to refresh the Laravel side.
 *
 * @internal
 */
#[CoversNothing]
final class MusicCatalogOpenApiExportTest extends Orchestra
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

    public function test_it_exports_the_music_catalog_document_for_every_server(): void
    {
        $buildDir = \dirname(__DIR__, 2) . '/build';
        if (!\is_dir($buildDir)) {
            \mkdir($buildDir, 0o755, true);
        }

        foreach (['default', 'admin'] as $server) {
            $path = \sprintf('%s/laravel-%s.json', $buildDir, $server);

            // Strict: every music-catalog operation carries an example, overrides included.
            $exitCode = Artisan::call('jsonapi:openapi:export', ['--server' => $server, '--output' => $path, '--strict' => true]);
            self::assertSame(0, $exitCode);

            self::assertFileExists($path);
            $contents = (string) \file_get_contents($path);
            self::assertJson($contents);

            /** @var array<string, mixed> $document */
            $document = \json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('openapi', $document);
            self::assertArrayHasKey('paths', $document);
        }
    }

    public function test_the_album_download_documents_both_of_its_responses(): void
    {
        $path = \sys_get_temp_dir() . '/jsonapi-download-' . \uniqid() . '.json';
        self::assertSame(0, Artisan::call('jsonapi:openapi:export', ['--output' => $path, '--strict' => true]));

        /** @var array{paths: array<string, array<string, array{responses: array<int, array{description: string, content: array<string, mixed>, headers: array<string, mixed>}>}>>} $document */
        $document = \json_decode((string) \file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        @\unlink($path);

        $responses = $document['paths']['/albums/{id}/-actions/download']['get']['responses'];
        self::assertSame('A short-lived link to the album as CSV.', $responses[200]['description']);
        self::assertArrayHasKey('application/vnd.api+json', $responses[200]['content']);
        self::assertArrayHasKey('Cache-Control', $responses[200]['headers']);
        self::assertSame('A short-lived link to the album as CSV.', $responses[303]['description']);
        self::assertArrayHasKey('Location', $responses[303]['headers']);
        self::assertArrayHasKey('Cache-Control', $responses[303]['headers']);
    }
}
