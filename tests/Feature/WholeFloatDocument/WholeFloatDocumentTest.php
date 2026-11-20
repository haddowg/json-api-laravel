<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\WholeFloatDocument;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\WholeFloatDocument\WholeFloatDocumentServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * A served or exported JSON Schema document keeps the fraction of a whole float, as a
 * response does, so a `Decimal` example of `5.0` reads `5.0`.
 */
#[CoversNothing]
final class WholeFloatDocumentTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    protected function getPackageProviders($app): array
    {
        return [JsonApiServiceProvider::class, WholeFloatDocumentServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', true);
        $app['config']->set('jsonapi.openapi.cache_path', \sys_get_temp_dir() . '/jsonapi-artifacts-' . \uniqid());
    }

    #[Test]
    public function theServedSchemasKeepTheFractionOfAWholeFloat(): void
    {
        $body = \str_replace([' ', "\n"], '', (string) $this->get('/schemas.json')->assertOk()->getContent());

        self::assertStringContainsString('"exclusiveMinimum":0.0', $body);
        self::assertStringContainsString('"examples":[5.0]', $body);
    }

    #[Test]
    public function theExportedSchemasKeepTheFractionOfAWholeFloat(): void
    {
        $this->jsonApiArtisan('jsonapi:jsonschema:export')
            ->expectsOutputToContain('"exclusiveMinimum": 0.0')
            ->assertSuccessful();
    }
}
