<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Illuminate\Testing\TestResponse;
use Illuminate\Translation\Translator;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\ValidationInMemoryServiceProvider;

/**
 * End-to-end witness that the package localizes core's error catalogue through the
 * Laravel translator (ADR 0023). Under a non-default app locale (`fr`) with
 * `jsonapi-errors` lines, real HTTP requests render French error documents:
 *
 *  - a `404` localizes its `title` while its parameter-free `detail` falls back to
 *    core's English (per-slot resolution);
 *  - a `400` localizes both its `title` and its `detail`, the French template's
 *    `{param}` token interpolated from the error's context;
 *  - a `422` the validation bridge builds (`VALIDATION_FAILED`) localizes its `title`
 *    through core's uniform resolver reach.
 *
 * Single provider (the in-memory validation wiring): the error render path is
 * provider-agnostic (core's `ErrorResponse` + the resolver, no data layer), so an
 * Eloquent twin would exercise the identical path for no added coverage.
 *
 * @internal
 */
final class ErrorLocalizationTest extends Orchestra
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
            ValidationInMemoryServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('jsonapi.base_uri', 'http://localhost/api');
        // A non-default app locale: the resolver looks up jsonapi-errors in fr.
        $config->set('app.locale', 'fr');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The fr error catalogue — the app-side equivalent of a lang/fr/jsonapi-errors.php
        // file, keyed by the stable error code in the jsonapi-errors group.
        $app = $this->app;
        \assert($app !== null);
        $translator = $app->make('translator');
        \assert($translator instanceof Translator);
        $translator->addLines([
            'jsonapi-errors.RESOURCE_NOT_FOUND.title' => 'Ressource introuvable',
            'jsonapi-errors.QUERY_PARAM_UNRECOGNIZED.title' => 'Paramètre de requête non reconnu',
            'jsonapi-errors.QUERY_PARAM_UNRECOGNIZED.detail' => "Le paramètre de requête '{param}' n'est pas reconnu par le point de terminaison.",
            'jsonapi-errors.VALIDATION_FAILED.title' => 'Entité non traitable',
        ], 'fr');
    }

    #[Test]
    #[Group('errors')]
    public function aCatalogueErrorLocalizesItsTitleAndFallsBackPerSlotForAnUntranslatedDetail(): void
    {
        $response = $this->readJsonApi('/api/articles/999');
        $response->assertStatus(404);

        // code + status are the machine/HTTP contract — untouched.
        self::assertSame('RESOURCE_NOT_FOUND', $response->json('errors.0.code'));
        self::assertSame('404', $response->json('errors.0.status'));
        // The title is localized from the fr catalogue…
        self::assertSame('Ressource introuvable', $response->json('errors.0.title'));
        // …while the detail — which the catalogue does not translate — falls back to
        // core's English default (per-slot resolution).
        self::assertSame('The requested resource is not found!', $response->json('errors.0.detail'));
    }

    #[Test]
    #[Group('errors')]
    public function aCatalogueErrorLocalizesTitleAndInterpolatesTheDetailTemplate(): void
    {
        // Strict query parameters reject an unknown family with a 400 whose context
        // carries the offending name — a clean single param.
        $response = $this->readJsonApi('/api/articles?bogus=1');
        $response->assertStatus(400);

        self::assertSame('QUERY_PARAM_UNRECOGNIZED', $response->json('errors.0.code'));
        self::assertSame('Paramètre de requête non reconnu', $response->json('errors.0.title'));
        // The French detail template's {param} token is filled from the error context.
        self::assertSame(
            "Le paramètre de requête 'bogus' n'est pas reconnu par le point de terminaison.",
            $response->json('errors.0.detail'),
        );
    }

    #[Test]
    #[Group('errors')]
    public function theValidation422TitleLocalizesThroughTheUniformResolverReach(): void
    {
        // A create missing the required `title` is a 422 the validation bridge builds with
        // code VALIDATION_FAILED; core applies the resolver to it uniformly, so its title
        // localizes through the very same catalogue.
        $response = $this->json('POST', '/api/articles', [
            'data' => ['type' => 'articles', 'attributes' => ['body' => 'Sans titre.', 'category' => 'news']],
        ], ['Accept' => self::MEDIA_TYPE, 'CONTENT_TYPE' => self::MEDIA_TYPE]);

        $response->assertStatus(422);
        self::assertSame('VALIDATION_FAILED', $response->json('errors.0.code'));
        self::assertSame('Entité non traitable', $response->json('errors.0.title'));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function readJsonApi(string $uri): TestResponse
    {
        return $this->json('GET', $uri, [], ['Accept' => self::MEDIA_TYPE]);
    }
}
