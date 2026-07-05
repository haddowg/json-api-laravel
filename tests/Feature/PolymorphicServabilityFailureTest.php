<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\PolymorphicServability\PolymorphicServabilityServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The servability guard's polymorphic-discrimination failure path (PLAN decision 11): a
 * polymorphic relation candidate that does not override `getType()` would silently claim
 * (and mis-serialize) members of its sibling type, so `jsonapi:optimize` must flag it and
 * fail the deploy.
 *
 * @internal
 */
final class PolymorphicServabilityFailureTest extends Orchestra
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
            PolymorphicServabilityServiceProvider::class,
        ];
    }

    #[Test]
    #[Group('openapi')]
    public function theWarmerReportsANonDiscriminatingPolymorphicCandidate(): void
    {
        $problems = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());

        $this->assertStringContainsString('flawed_members', $problems);
        $this->assertStringContainsString('getType', $problems);
    }

    #[Test]
    #[Group('openapi')]
    public function optimizeFailsTheDeployOnANonDiscriminatingCandidate(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(1);
    }
}
