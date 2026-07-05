<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\OrphanStandaloneServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The servability guard's failure path for the **serializer channel** (PLAN decision 3,
 * bundle ADR 0024): a standalone-serializer type whose allow-list opens the fetch
 * endpoints but has no data provider is exactly as unservable as a provider-less
 * resource, and must fail the BUILD (`jsonapi:optimize`) rather than 500 at runtime.
 * The provider-less {@see \haddowg\JsonApiLaravel\Tests\Fixtures\OrphanStandaloneSerializer}
 * is the unservable type — this proves the warmer walks the serializer channel, not just
 * the resource channel ({@see OpenApiServabilityTest}'s concern).
 *
 * @internal
 */
final class StandaloneSerializerServabilityTest extends Orchestra
{
    use InteractsWithOpenApiDocument;

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            OrphanStandaloneServiceProvider::class,
        ];
    }

    #[Test]
    #[Group('openapi')]
    public function the_warmer_reports_the_standalone_type_missing_its_provider(): void
    {
        $problems = $this->resolve(ServableResourceWarmer::class)->warm();

        $this->assertNotEmpty($problems);
        $joined = \implode("\n", $problems);
        $this->assertStringContainsString('orphan-charts', $joined);
        $this->assertStringContainsString('DataProvider', $joined);
        // A standalone serializer opens no write operations, so the persister guard
        // never fires for it.
        $this->assertStringNotContainsString('DataPersister', $joined);
    }

    #[Test]
    #[Group('openapi')]
    public function optimize_fails_the_deploy_on_an_unservable_standalone_type(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(1);
    }
}
