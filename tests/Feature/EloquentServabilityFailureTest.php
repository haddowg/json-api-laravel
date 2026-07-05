<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature;

use haddowg\JsonApiLaravel\JsonApiServiceProvider;
use haddowg\JsonApiLaravel\Server\ServableResourceWarmer;
use haddowg\JsonApiLaravel\Tests\Fixtures\Servability\ServabilityFailureServiceProvider;
use haddowg\JsonApiLaravel\Tests\Support\InteractsWithOpenApiDocument;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Eloquent servability guards' failure paths (PLAN decision 11): against a real migrated
 * `albums` table, `jsonapi:optimize` must report a sortable / filter pointing at a
 * non-existent column and a relation naming no model method — failing the deploy rather than
 * 500-ing on the first request.
 *
 * @internal
 */
final class EloquentServabilityFailureTest extends Orchestra
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
            ServabilityFailureServiceProvider::class,
        ];
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var \Illuminate\Contracts\Config\Repository $config */
        $config = $app['config'];
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__, 2) . '/workbench/database/migrations');
    }

    #[Test]
    #[Group('openapi')]
    public function theWarmerReportsBadSortableAndFilterColumns(): void
    {
        $problems = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());

        $this->assertStringContainsString('nonexistent_sort_column', $problems);
        $this->assertStringContainsString('nonexistent_filter_column', $problems);
        $this->assertStringContainsString('bogusSort', $problems);
        $this->assertStringContainsString('bogusFilter', $problems);
    }

    #[Test]
    #[Group('openapi')]
    public function theWarmerReportsATypoedRelationMethod(): void
    {
        $problems = \implode("\n", $this->resolve(ServableResourceWarmer::class)->warm());

        $this->assertStringContainsString('ghostRelation', $problems);
    }

    #[Test]
    #[Group('openapi')]
    public function optimizeFailsTheDeployOnBrokenColumns(): void
    {
        $this->jsonApiArtisan('jsonapi:optimize')->assertExitCode(1);
    }
}
