<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\Atomic\AtomicExtension;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\SurfaceInMemoryServiceProvider;

/**
 * The Atomic Operations endpoint is opt-in: with `jsonapi.atomic_operations.enabled` left at
 * its default `false`, the route registrar emits NO `/operations` route, so a batch request
 * is a plain router `404` — the extension is off, not merely erroring. (The enabled path is
 * exercised by {@see \haddowg\JsonApiLaravel\Tests\Conformance\AtomicConformanceTestCase}.)
 *
 * @internal
 */
final class AtomicDisabledTest extends Orchestra
{
    public const string ATOMIC_MEDIA_TYPE = 'application/vnd.api+json; ext="' . AtomicExtension::URI . '"';

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            SurfaceInMemoryServiceProvider::class,
        ];
    }

    #[Test]
    #[Group('spec:atomic')]
    public function theOperationsEndpointIs404WhenTheExtensionIsDisabled(): void
    {
        $body = (string) \json_encode(['atomic:operations' => [
            ['op' => 'add', 'data' => ['type' => 'artists', 'attributes' => ['name' => 'X', 'slug' => 'x']]],
        ]]);

        $this->call('POST', '/api/operations', [], [], [], [
            'HTTP_ACCEPT' => self::ATOMIC_MEDIA_TYPE,
            'CONTENT_TYPE' => self::ATOMIC_MEDIA_TYPE,
        ], $body)->assertNotFound();
    }
}
