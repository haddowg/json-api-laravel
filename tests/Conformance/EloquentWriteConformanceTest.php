<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Providers\EloquentWorkbenchServiceProvider;
use Workbench\Database\Seeders\MusicCatalogSeeder;

/**
 * {@see WriteConformanceTestCase} against the **reference Eloquent provider/persister
 * pair** executed as real SQL over an in-memory SQLite database. The
 * {@see EloquentWorkbenchServiceProvider} registers the pair at `-128` over the SAME
 * `app/JsonApi` resources; the minimal {@see \Workbench\App\Support\Fixtures} are seeded
 * via {@see MusicCatalogSeeder} — the SAME two albums the in-memory witness carries — so
 * a created row is the predictable auto-increment id `3` on both, refereeing the Eloquent
 * write + transaction semantics against the witness.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentWriteConformanceTest extends WriteConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return EloquentWorkbenchServiceProvider::class;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

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
        (new MusicCatalogSeeder())->run();
    }

    #[Test]
    #[Group('spec:crud')]
    public function linkageToAnIntegerKeyedTypeNamingAnIdThatIsNotAnIntegerIs404(): void
    {
        foreach (['abc', '01'] as $id) {
            $response = $this->writeJsonApi('POST', '/api/albums', [
                'data' => [
                    'type' => 'albums',
                    'attributes' => ['title' => 'Unattached', 'status' => 'released', 'releasedAt' => '2020-02-02T00:00:00+00:00'],
                    'relationships' => ['artist' => ['data' => ['type' => 'artists', 'id' => $id]]],
                ],
            ]);

            $response->assertStatus(404);
            self::assertSame('RESOURCE_NOT_FOUND', $response->json('errors.0.code'), $id);
        }
    }
}
