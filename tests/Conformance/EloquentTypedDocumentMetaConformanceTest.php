<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\TmBoard;
use Workbench\App\Models\TmCard;
use Workbench\App\Providers\TypedMetaEloquentServiceProvider;

/**
 * {@see TypedDocumentMetaConformanceTestCase} on Eloquent over SQLite.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentTypedDocumentMetaConformanceTest extends TypedDocumentMetaConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return TypedMetaEloquentServiceProvider::class;
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

    protected function setUp(): void
    {
        parent::setUp();

        $board = TmBoard::query()->create(['name' => 'Sprint']);
        foreach ([['Plan', false], ['Build', true], ['Ship', false]] as [$title, $done]) {
            TmCard::query()->create(['title' => $title, 'done' => $done, 'board_id' => $board->id]);
        }
        $board->update(['pinned_id' => 3]);
    }
}
