<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Route-gating tests for the OpenAPI documentation endpoints (PLAN decision 11): the
 * `/docs.json`, `/schemas.json` and `/docs` routes are registered only when `app.debug`
 * is true OR `jsonapi.openapi.expose_in_prod` is true, and each sub-route respects its own
 * `enabled` toggle. Registration happens at boot from config alone, so each scenario boots
 * the app under a different `#[DefineEnvironment]`.
 *
 * @internal
 */
final class OpenApiRouteGatingTest extends TestCase
{
    public static function debugOn(mixed $app): void
    {
        self::config($app)->set('app.debug', true);
    }

    public static function hiddenInProd(mixed $app): void
    {
        self::config($app)->set('app.debug', false);
        self::config($app)->set('jsonapi.openapi.expose_in_prod', false);
    }

    public static function exposedInProd(mixed $app): void
    {
        self::config($app)->set('app.debug', false);
        self::config($app)->set('jsonapi.openapi.expose_in_prod', true);
    }

    public static function schemaDisabled(mixed $app): void
    {
        self::config($app)->set('app.debug', true);
        self::config($app)->set('jsonapi.openapi.json_schema.enabled', false);
    }

    public static function uiDisabled(mixed $app): void
    {
        self::config($app)->set('app.debug', true);
        self::config($app)->set('jsonapi.openapi.ui.enabled', false);
    }

    public static function generationDisabled(mixed $app): void
    {
        self::config($app)->set('app.debug', true);
        self::config($app)->set('jsonapi.openapi.enabled', false);
    }

    public static function redocRenderer(mixed $app): void
    {
        self::config($app)->set('app.debug', true);
        self::config($app)->set('jsonapi.openapi.ui.renderer', 'redoc');
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('debugOn')]
    public function it_serves_the_docs_routes_in_debug(): void
    {
        $this->get('/docs.json')->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->get('/schemas.json')->assertOk()->assertHeader('Content-Type', 'application/json');
        $this->get('/docs')->assertOk();
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('debugOn')]
    public function it_serves_the_openapi_document_as_a_valid_json_document(): void
    {
        $body = $this->get('/docs.json')->assertOk()->getContent();
        $this->assertIsString($body);

        $decoded = \json_decode($body, true);
        $this->assertIsArray($decoded);
        $openapi = $decoded['openapi'] ?? null;
        $this->assertIsString($openapi);
        $this->assertStringStartsWith('3.1', $openapi);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('debugOn')]
    public function it_renders_the_swagger_ui_referencing_the_document(): void
    {
        $body = (string) $this->get('/docs')->assertOk()->getContent();

        // The default renderer is Swagger UI, and the page must fetch the resolved docs.json.
        $this->assertStringContainsString('SwaggerUIBundle', $body);
        $this->assertStringContainsString('/docs.json', $body);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('redocRenderer')]
    public function it_renders_the_redoc_ui_when_selected(): void
    {
        $body = (string) $this->get('/docs')->assertOk()->getContent();

        // The `redoc` renderer branch emits the <redoc> element pointed at the docs.json spec.
        $this->assertStringContainsString('<redoc', $body);
        $this->assertStringContainsString('redoc.standalone.js', $body);
        $this->assertStringContainsString('/docs.json', $body);
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('hiddenInProd')]
    public function it_hides_the_docs_routes_outside_debug_by_default(): void
    {
        $this->get('/docs.json')->assertNotFound();
        $this->get('/schemas.json')->assertNotFound();
        $this->get('/docs')->assertNotFound();
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('exposedInProd')]
    public function it_exposes_the_docs_routes_in_prod_when_opted_in(): void
    {
        $this->get('/docs.json')->assertOk();
        $this->get('/docs')->assertOk();
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('schemaDisabled')]
    public function it_omits_the_schema_route_when_disabled(): void
    {
        $this->get('/docs.json')->assertOk();
        $this->get('/schemas.json')->assertNotFound();
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('uiDisabled')]
    public function it_omits_the_ui_route_when_disabled(): void
    {
        $this->get('/docs.json')->assertOk();
        $this->get('/docs')->assertNotFound();
    }

    #[Test]
    #[Group('openapi')]
    #[DefineEnvironment('generationDisabled')]
    public function it_omits_every_docs_route_when_generation_is_disabled(): void
    {
        $this->get('/docs.json')->assertNotFound();
        $this->get('/schemas.json')->assertNotFound();
        $this->get('/docs')->assertNotFound();
    }

    private static function config(mixed $app): Repository
    {
        \assert($app instanceof \ArrayAccess);
        $config = $app['config'];
        \assert($config instanceof Repository);

        return $config;
    }
}
