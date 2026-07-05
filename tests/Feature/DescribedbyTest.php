<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
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
            ->assertJsonPath('links.describedby', fn(mixed $link): bool => \is_string($link) && \str_contains($link, '/docs.json'));
    }

    #[Test]
    #[Group('openapi')]
    public function noDescribedbyIsAddedWhenTheDocumentRoutesAreNotExposed(): void
    {
        // With app.debug false and expose_in_prod false the docs routes are not registered,
        // so no link is advertised to a document that is not served.
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
