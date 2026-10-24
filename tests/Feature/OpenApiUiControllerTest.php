<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\Http\OpenApiUiController;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The ReDoc page the viewer renders for each supported major: the 3.x ES module with
 * telemetry off unless the app opts in, and the 2.x global script.
 *
 * @internal
 */
#[Group('openapi')]
final class OpenApiUiControllerTest extends TestCase
{
    public static function redoc(mixed $app): void
    {
        self::config($app)->set('app.debug', true);
        self::config($app)->set('jsonapi.openapi.ui.renderer', 'redoc');
    }

    #[Test]
    #[DefineEnvironment('redoc')]
    public function redoc_defaults_to_the_three_x_module_with_telemetry_off(): void
    {
        $body = $this->render();

        self::assertSame('3.0.1', OpenApiUiController::REDOC_VERSION);
        self::assertStringContainsString('<redoc spec-url="' . $this->specUrl() . '" disable-telemetry="true"></redoc>', $body);
        self::assertStringContainsString(
            '<script type="module" src="https://cdn.jsdelivr.net/npm/redoc@3.0.1/bundles/redoc.standalone.js" crossorigin></script>',
            $body,
        );
    }

    #[Test]
    #[DefineEnvironment('redoc')]
    public function an_app_that_opts_into_telemetry_gets_no_disable_attribute(): void
    {
        $body = $this->render(telemetry: true);

        self::assertStringContainsString('<redoc spec-url="' . $this->specUrl() . '"></redoc>', $body);
        self::assertStringNotContainsString('disable-telemetry', $body);
        self::assertStringContainsString('<script type="module" src="https://cdn.jsdelivr.net/npm/redoc@3.0.1/bundles/redoc.standalone.js"', $body);
    }

    #[Test]
    #[DefineEnvironment('redoc')]
    public function a_two_x_version_loads_the_global_script(): void
    {
        $body = $this->render(version: OpenApiUiController::REDOC_VERSION_2);

        self::assertSame('2.5.4', OpenApiUiController::REDOC_VERSION_2);
        self::assertStringContainsString('<redoc spec-url="' . $this->specUrl() . '"></redoc>', $body);
        self::assertStringContainsString(
            '<script src="https://cdn.jsdelivr.net/npm/redoc@2.5.4/bundles/redoc.standalone.js" crossorigin></script>',
            $body,
        );
        self::assertStringNotContainsString('type="module"', $body);
        self::assertStringNotContainsString('disable-telemetry', $body);
    }

    #[Test]
    #[DefineEnvironment('redoc')]
    public function a_custom_cdn_serves_either_major(): void
    {
        $three = $this->render(cdn: 'https://assets.example.test/redoc/');
        self::assertStringContainsString('<script type="module" src="https://assets.example.test/redoc/redoc.standalone.js" crossorigin></script>', $three);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $three);

        $two = $this->render(version: '2.5.4', cdn: 'https://assets.example.test/redoc');
        self::assertStringContainsString('<script src="https://assets.example.test/redoc/redoc.standalone.js" crossorigin></script>', $two);
        self::assertStringNotContainsString('cdn.jsdelivr.net', $two);
    }

    #[Test]
    #[DefineEnvironment('redoc')]
    public function swagger_ui_is_unchanged(): void
    {
        self::config($this->app)->set('jsonapi.openapi.ui.renderer', 'swagger');

        $body = (string) $this->get('/docs')->assertOk()->getContent();

        self::assertStringContainsString('https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js', $body);
        self::assertStringNotContainsString('redoc', $body);
    }

    private function render(string $version = OpenApiUiController::REDOC_VERSION, bool $telemetry = false, ?string $cdn = null): string
    {
        $config = self::config($this->app);
        $config->set('jsonapi.openapi.ui.redoc_version', $version);
        $config->set('jsonapi.openapi.ui.redoc_telemetry', $telemetry);
        $config->set('jsonapi.openapi.ui.cdn', $cdn);

        return (string) $this->get('/docs')->assertOk()->getContent();
    }

    private function specUrl(): string
    {
        return route(OpenApiUiController::DOCUMENT_ROUTE);
    }

    private static function config(mixed $app): Repository
    {
        \assert($app instanceof \ArrayAccess);
        $config = $app['config'];
        \assert($config instanceof Repository);

        return $config;
    }
}
