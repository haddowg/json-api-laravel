<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\RelatedTargetServability\RelatedTargetServabilityServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The servability guard's related-endpoint-target failure path: a relation exposing
 * `GET /{type}/{id}/{rel}` to a type the server does not register has no serializer to
 * render the response and no field inventory to describe it, so `jsonapi:optimize` must
 * flag it and fail the deploy rather than leave a 500 waiting on the first request (and a
 * refused OpenAPI export later).
 *
 * @internal
 */
final class RelatedTargetServabilityFailureTest extends Orchestra
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
            RelatedTargetServabilityServiceProvider::class,
        ];
    }

    #[Test]
    #[Group('openapi')]
    public function theWarmerReportsARelatedEndpointAimedAtAnUnregisteredType(): void
    {
        $problems = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());

        // The report names the parent type, the relation, the related type and the server,
        // then offers the three ways out.
        $this->assertStringContainsString('"crates" on type "shelves"', $problems);
        $this->assertStringContainsString('not registered on server "default"', $problems);
        $this->assertStringContainsString('withoutRelatedEndpoint', $problems);

        // The linkage-only sibling is silent: a linkage asserts no shape, so an
        // unregistered target is legitimate there.
        $this->assertStringNotContainsString('"pallets"', $problems);
    }

    #[Test]
    #[Group('openapi')]
    public function optimizeFailsTheDeployOnAnUnregisteredRelatedTarget(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(1);
    }
}
