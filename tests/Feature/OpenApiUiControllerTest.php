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
        self::assertStringContainsString('<redoc spec-url="' . $this->specUrl() . '" download-urls="' . $this->downloadUrlsAttribute() . '" disable-telemetry="true"></redoc>', $body);
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

        self::assertStringContainsString('<redoc spec-url="' . $this->specUrl() . '" download-urls="' . $this->downloadUrlsAttribute() . '"></redoc>', $body);
        self::assertStringNotContainsString('disable-telemetry', $body);
        self::assertStringContainsString('<script type="module" src="https://cdn.jsdelivr.net/npm/redoc@3.0.1/bundles/redoc.standalone.js"', $body);
    }

    #[Test]
    #[DefineEnvironment('redoc')]
    public function a_two_x_version_loads_the_global_script(): void
    {
        $body = $this->render(version: OpenApiUiController::REDOC_VERSION_2);

        self::assertSame('2.5.4', OpenApiUiController::REDOC_VERSION_2);
        // 2.x reads tag attributes as strings, so the page initialises it with the options.
        self::assertStringContainsString('<redoc></redoc>', $body);
        self::assertStringContainsString('Redoc.init("' . $this->specUrl() . '", {"downloadUrls":' . $this->downloadUrlsJson() . "}, document.querySelector('redoc'));", $body);
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
    public function both_redoc_lines_offer_the_json_and_yaml_downloads(): void
    {
        $json = route(OpenApiUiController::DOCUMENT_ROUTE);
        $yaml = route(OpenApiUiController::YAML_DOCUMENT_ROUTE);
        $expected = [['title' => 'JSON', 'url' => $json], ['title' => 'YAML', 'url' => $yaml]];

        self::assertSame('http://localhost/docs.yaml', $yaml);
        self::assertSame($expected, $this->redoc3DownloadUrls($this->render()));
        self::assertSame($expected, $this->redoc2DownloadUrls($this->render(version: OpenApiUiController::REDOC_VERSION_2)));
    }

    #[Test]
    #[DefineEnvironment('redocWithoutYaml')]
    public function without_yaml_both_redoc_lines_offer_only_the_json_download(): void
    {
        $expected = [['title' => 'JSON', 'url' => route(OpenApiUiController::DOCUMENT_ROUTE)]];

        self::assertSame($expected, $this->redoc3DownloadUrls($this->render()));
        self::assertSame($expected, $this->redoc2DownloadUrls($this->render(version: OpenApiUiController::REDOC_VERSION_2)));
    }

    #[Test]
    #[DefineEnvironment('redoc')]
    public function the_download_urls_follow_the_request_host(): void
    {
        $body = (string) $this->get('http://docs.tenant.test/docs')->assertOk()->getContent();

        self::assertSame([
            ['title' => 'JSON', 'url' => 'http://docs.tenant.test/docs.json'],
            ['title' => 'YAML', 'url' => 'http://docs.tenant.test/docs.yaml'],
        ], $this->redoc3DownloadUrls($body));
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

    public static function redocWithoutYaml(mixed $app): void
    {
        self::redoc($app);
        self::config($app)->set('jsonapi.openapi.yaml.enabled', false);
    }

    private function specUrl(): string
    {
        return route(OpenApiUiController::DOCUMENT_ROUTE);
    }

    private function downloadUrlsJson(): string
    {
        return (string) \json_encode([
            ['title' => 'JSON', 'url' => route(OpenApiUiController::DOCUMENT_ROUTE)],
            ['title' => 'YAML', 'url' => route(OpenApiUiController::YAML_DOCUMENT_ROUTE)],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private function downloadUrlsAttribute(): string
    {
        return \htmlspecialchars($this->downloadUrlsJson(), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    /**
     * The `download-urls` attribute of the 3.x page's tag, decoded as a browser would.
     *
     * @return mixed
     */
    private function redoc3DownloadUrls(string $body): mixed
    {
        if (\preg_match('#<redoc [^>]*download-urls="([^"]*)"#', $body, $match) !== 1) {
            self::fail('The <redoc> tag carries no download-urls attribute.');
        }

        return \json_decode(\html_entity_decode($match[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * The `downloadUrls` the 2.x page passes to `Redoc.init()`.
     *
     * @return mixed
     */
    private function redoc2DownloadUrls(string $body): mixed
    {
        self::assertStringNotContainsString('download-urls', $body);
        if (\preg_match('#Redoc\.init\("[^"]*", (\{.*\}), document\.querySelector\(\'redoc\'\)\);#', $body, $match) !== 1) {
            self::fail('The page does not call Redoc.init() with options.');
        }
        $options = \json_decode($match[1], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($options);

        return $options['downloadUrls'] ?? null;
    }

    private static function config(mixed $app): Repository
    {
        \assert($app instanceof \ArrayAccess);
        $config = $app['config'];
        \assert($config instanceof Repository);

        return $config;
    }
}
