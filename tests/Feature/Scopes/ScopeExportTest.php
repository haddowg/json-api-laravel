<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Scopes;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\Scopes\PartialScopeCatalogServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use haddowg\JsonApiLaravel\Tests\Support\PinsCatalogClock;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\MusicCatalog\Providers\MusicCatalogInMemoryServiceProvider;
use Workbench\App\MusicCatalog\Support\CatalogConfig;

/**
 * The OpenAPI export's scope diagnostics over the music catalogue: a required scope the
 * catalogue does not declare is a warning, and a failure under `--strict`; a scope scheme
 * that is not oauth2 is only ever a warning.
 *
 * @internal
 */
#[CoversNothing]
final class ScopeExportTest extends Orchestra
{
    use InteractsWithOpenApiDocument;
    use PinsCatalogClock;

    private bool $partialCatalog = false;

    private ?string $scheme = null;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        $providers = [JsonApiServiceProvider::class, MusicCatalogInMemoryServiceProvider::class];
        if ($this->partialCatalog) {
            $providers[] = PartialScopeCatalogServiceProvider::class;
        }

        return $providers;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        CatalogConfig::apply($config);
        if ($this->scheme !== null) {
            $config->set('jsonapi.openapi.security.scope_scheme', $this->scheme);
        }
    }

    public function test_the_catalogue_exports_clean_under_strict(): void
    {
        $this->jsonApiArtisan('jsonapi:openapi:export', ['--strict' => true, '--output' => $this->file()])
            ->doesntExpectOutputToContain('Warning:')
            ->assertExitCode(0);
    }

    public function test_an_undeclared_scope_is_a_warning_and_fails_strict(): void
    {
        $this->partialCatalog = true;
        $this->refreshApplication();

        $this->jsonApiArtisan('jsonapi:openapi:export', ['--output' => $this->file()])
            ->expectsOutputToContain('Warning: The scope "batch" is required by atomic.operations')
            ->assertExitCode(0);

        $file = $this->file();
        $this->jsonApiArtisan('jsonapi:openapi:export', ['--strict' => true, '--output' => $file])
            ->expectsOutputToContain('1 required scope(s) undeclared, and --strict fails the export on any.')
            ->assertExitCode(1);
        self::assertFileDoesNotExist($file);
    }

    public function test_a_scope_scheme_that_is_not_oauth2_is_only_a_warning(): void
    {
        $this->scheme = 'bearer';
        $this->refreshApplication();

        $this->jsonApiArtisan('jsonapi:openapi:export', ['--strict' => true, '--output' => $this->file()])
            ->expectsOutputToContain('listed under the "bearer" security scheme, which is http, not oauth2')
            ->assertExitCode(0);
    }

    private function file(): string
    {
        return \sys_get_temp_dir() . '/jsonapi-scope-export-' . \bin2hex(\random_bytes(4)) . '.json';
    }
}
