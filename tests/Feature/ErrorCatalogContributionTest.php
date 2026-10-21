<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApi\OpenApi\ErrorCatalogProjector;
use haddowg\JsonApiLaravel\Discovery\Discovery;
use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\OpenApi\DocumentFactory;
use haddowg\JsonApiLaravel\Tests\Fixtures\ErrorCatalog\ErrorCatalogServiceProvider;
use haddowg\JsonApiLaravel\Tests\Fixtures\ErrorCatalog\Scanned\QuotaExceeded;
use haddowg\JsonApiLaravel\Tests\Fixtures\ErrorCatalog\Unscanned\SubscriptionRequired;
use haddowg\JsonApiLaravel\Tests\Fixtures\ErrorCatalog\Unscanned\TenantSuspended;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\WorkbenchServiceProvider;

/**
 * The application-contributed error catalogue (core ADR 0136): an app's own described
 * errors reach the projected document as named schema variants beside core's. Discovery
 * finds them under the configured paths, and `JsonApi::register()` names the ones no
 * scan reaches.
 *
 * The load-bearing test is {@see an_error_outside_the_scanned_paths_is_not_catalogued}.
 * Discovery that reached beyond its configured paths would publish whatever described
 * error happened to be lying around — a fixture, a scratch class — as part of the API's
 * contract, so the scoping is the feature rather than an implementation detail of it.
 *
 * @internal
 */
final class ErrorCatalogContributionTest extends TestCase
{
    use InteractsWithOpenApiDocument;

    #[Test]
    #[Group('openapi')]
    public function an_error_under_a_scanned_path_is_catalogued(): void
    {
        $schemas = $this->schemas();
        $component = ErrorCatalogProjector::componentName(QuotaExceeded::describe()->code);

        $this->assertArrayHasKey($component, $schemas);

        // The variant narrows the shared `Error` with the two members a client dispatches
        // on, so both are readable off `allOf[1]`.
        $this->assertSame('QUOTA_EXCEEDED', $this->at($schemas, $component, 'allOf', 1, 'properties', 'code', 'const'));
        $this->assertSame('429', $this->at($schemas, $component, 'allOf', 1, 'properties', 'status', 'const'));

        $this->assertContains(
            ['$ref' => '#/components/schemas/' . $component],
            $this->errorBranches($schemas),
        );
    }

    #[Test]
    #[Group('openapi')]
    public function an_error_outside_the_scanned_paths_is_not_catalogued(): void
    {
        $this->assertArrayNotHasKey(
            ErrorCatalogProjector::componentName(SubscriptionRequired::describe()->code),
            $this->schemas(),
        );
    }

    #[Test]
    #[Group('openapi')]
    public function an_error_outside_the_scanned_paths_is_catalogued_when_registered_explicitly(): void
    {
        $schemas = $this->schemas();
        $component = ErrorCatalogProjector::componentName(TenantSuspended::describe()->code);

        $this->assertArrayHasKey($component, $schemas);
        $this->assertContains(
            ['$ref' => '#/components/schemas/' . $component],
            $this->errorBranches($schemas),
        );
    }

    #[Test]
    #[Group('openapi')]
    public function cores_own_codes_are_still_catalogued(): void
    {
        // The contribution joins core's catalogue; it never replaces it.
        $this->assertArrayHasKey('ResourceNotFoundError', $this->schemas());
    }

    #[Test]
    #[Group('openapi')]
    public function the_discovered_errors_survive_the_optimize_snapshot(): void
    {
        // The snapshot is a faithful drop-in for a scan, so an optimized app documents the
        // same codes a scanning one does.
        $cacheFile = \sys_get_temp_dir() . '/jsonapi-error-catalog-' . \uniqid() . '.php';
        config(['jsonapi.discovery.cache' => $cacheFile]);
        config(['jsonapi.openapi.cache_path' => \sys_get_temp_dir() . '/jsonapi-artifacts-' . \uniqid()]);

        try {
            $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(0);

            $cached = new Discovery($this->resolve(\haddowg\JsonApiLaravel\Discovery\DiscoveryScanner::class), [], [], $cacheFile);

            $this->assertSame(
                [QuotaExceeded::class, TenantSuspended::class],
                $cached->errors(),
            );
        } finally {
            if (\is_file($cacheFile)) {
                @\unlink($cacheFile);
            }
        }
    }

    /**
     * The projected document's `components.schemas`.
     *
     * @return array<array-key, mixed>
     */
    private function schemas(): array
    {
        return $this->arrayAt(
            $this->resolve(DocumentFactory::class)->forServer()->toArray(),
            'components',
            'schemas',
        );
    }

    /**
     * The `anyOf` branches `ErrorDocument.errors.items` offers.
     *
     * @param array<array-key, mixed> $schemas
     *
     * @return list<mixed>
     */
    private function errorBranches(array $schemas): array
    {
        return \array_values($this->arrayAt($schemas, 'ErrorDocument', 'properties', 'errors', 'items', 'anyOf'));
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            JsonApiServiceProvider::class,
            WorkbenchServiceProvider::class,
            ErrorCatalogServiceProvider::class,
        ];
    }
}
