<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\ArtifactStore;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * `links.describedby` (PLAN decision 11, bundle ADR 0105): when the OpenAPI document routes
 * are reachable, every JSON:API response advertises a top-level `describedby` link to the
 * served document — gated on the same expose rule as the routes, and switchable off via
 * `jsonapi.openapi.describedby`.
 *
 * @internal
 */
final class DescribedbyTest extends Orchestra
{
    public const string MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            WorkbenchServiceProvider::class,
        ];
    }

    private string $cacheDir = '';

    /**
     * Each test reads its own artifacts, never another test's left in Testbench's storage.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $this->cacheDir = \sys_get_temp_dir() . '/jsonapi-describedby-' . \uniqid();

        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.openapi.cache_path', $this->cacheDir);
    }

    protected function tearDown(): void
    {
        $this->app?->make(ArtifactStore::class)->clear();
        @\rmdir($this->cacheDir);

        parent::tearDown();
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function exposeDocs($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.debug', true);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function hideDocs($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.debug', false);
        $config->set('jsonapi.openapi.expose_in_prod', false);
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function exposeDocsDescribedbyOff($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('app.debug', true);
        $config->set('jsonapi.openapi.describedby', false);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('exposeDocs')]
    public function aResponseAdvertisesTheServedDocumentWhenReachable(): void
    {
        $this->getJson('/api/artists', ['Accept' => self::MEDIA_TYPE])
            ->assertOk()
            ->assertJsonPath('links.describedby', ['href' => '/docs.json#/paths/~1artists/get']);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('hideDocs')]
    public function noDescribedbyIsAddedWhenTheDocumentRoutesAreNotExposed(): void
    {
        // Neither side of the expose gate is open, so the docs routes are not registered and
        // no link is advertised to a document that is not served.
        $this->getJson('/api/artists', ['Accept' => self::MEDIA_TYPE])
            ->assertOk()
            ->assertJsonMissingPath('links.describedby');
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('exposeDocsDescribedbyOff')]
    public function describedbyCanBeDisabledEvenWhenTheDocumentIsReachable(): void
    {
        $this->getJson('/api/artists', ['Accept' => self::MEDIA_TYPE])
            ->assertOk()
            ->assertJsonMissingPath('links.describedby');
    }
}
