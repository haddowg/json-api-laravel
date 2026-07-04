<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\ConformanceSeeder;

/**
 * {@see RelationshipWriteConformanceTestCase} against the **reference Eloquent
 * provider/persister pair** over real SQL (in-memory SQLite): the mutation arms drive
 * `associate`/`dissociate` (BelongsTo), `sync`/`syncWithoutDetaching`/`detach`
 * (belongsToMany, incl. the pivot upsert) inside a transaction, and the stored
 * `position`/`weight`/`addedAt` render as each member's `meta.pivot` (ADR 0008), refereed
 * against the witness by the identical assertions.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentRelationshipWriteConformanceTest extends RelationshipWriteConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return EloquentWorkbenchServiceProvider::class;
    }

    protected function providerRendersPivotMeta(): bool
    {
        return true;
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

    protected function seedConformanceData(): void
    {
        (new ConformanceSeeder())->run();
    }
}
