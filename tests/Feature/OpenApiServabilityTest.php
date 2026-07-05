<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\OrphanServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The servability guard's failure path (PLAN decision 11): a routed type with no data
 * provider/persister is an unservable configuration that must fail the BUILD
 * (`jsonapi:optimize`, a deploy step) rather than surface as a runtime 500. The
 * provider-less {@see \haddowg\JsonApiLaravel\Tests\Fixtures\OrphanResource} is the
 * unservable type.
 *
 * @internal
 */
final class OpenApiServabilityTest extends Orchestra
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
            OrphanServiceProvider::class,
        ];
    }

    #[Test]
    #[Group('openapi')]
    public function the_warmer_reports_the_missing_provider_and_persister(): void
    {
        $problems = $this->resolve(ServableResourceWarmer::class)->warm();

        $this->assertNotEmpty($problems);
        $joined = \implode("\n", $problems);
        $this->assertStringContainsString('orphans', $joined);
        $this->assertStringContainsString('DataProvider', $joined);
        $this->assertStringContainsString('DataPersister', $joined);
    }

    #[Test]
    #[Group('openapi')]
    public function optimize_fails_the_deploy_on_an_unservable_type(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(1);
    }
}
