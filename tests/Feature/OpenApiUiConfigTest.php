<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\Config\OpenApiConfig;
use haddowg\JsonApiLaravel\OpenApi\Config\OpenApiConfigResolver;
use Illuminate\Contracts\Config\Repository;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * How `jsonapi.openapi.ui.redoc_version` and `ui.redoc_telemetry` reach the viewer.
 *
 * @internal
 */
#[Group('openapi')]
final class OpenApiUiConfigTest extends TestCase
{
    public static function redoc2WithTelemetry(mixed $app): void
    {
        self::config($app)->set('jsonapi.openapi.ui.renderer', 'redoc');
        self::config($app)->set('jsonapi.openapi.ui.redoc_version', '2.5.4');
        self::config($app)->set('jsonapi.openapi.ui.redoc_telemetry', true);
    }

    #[Test]
    public function redoc_defaults_to_three_x_with_telemetry_off(): void
    {
        $ui = $this->resolve(OpenApiConfig::class)->ui;

        self::assertSame('3.0.1', $ui->redocVersion);
        self::assertFalse($ui->redocTelemetry);
    }

    #[Test]
    public function an_unset_version_falls_back_to_the_default(): void
    {
        $ui = (new OpenApiConfigResolver())->resolve(['ui' => []], ['default'])->ui;

        self::assertSame('3.0.1', $ui->redocVersion);
        self::assertFalse($ui->redocTelemetry);
    }

    #[Test]
    #[DefineEnvironment('redoc2WithTelemetry')]
    public function the_version_and_telemetry_are_configurable(): void
    {
        $ui = $this->resolve(OpenApiConfig::class)->ui;

        self::assertSame('2.5.4', $ui->redocVersion);
        self::assertTrue($ui->redocTelemetry);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidVersions(): iterable
    {
        yield 'a range' => ['^3.0'];
        yield 'a major only' => ['3'];
        yield 'an unsupported major' => ['1.0.0'];
        yield 'a tag' => ['latest'];
        yield 'not a string' => [3];
    }

    #[Test]
    #[DataProvider('invalidVersions')]
    public function an_inexact_or_unsupported_version_fails_the_boot(mixed $version): void
    {
        $app = $this->app;
        \assert($app !== null);
        self::config($app)->set('jsonapi.openapi.ui.redoc_version', $version);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/redoc_version must be an exact 2.x or 3.x version/');

        (new JsonApiServiceProvider($app))->boot();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $abstract
     *
     * @return T
     */
    private function resolve(string $abstract): object
    {
        $resolved = $this->app?->make($abstract);
        \assert($resolved instanceof $abstract);

        return $resolved;
    }

    private static function config(mixed $app): Repository
    {
        \assert($app instanceof \ArrayAccess);
        $config = $app['config'];
        \assert($config instanceof Repository);

        return $config;
    }
}
