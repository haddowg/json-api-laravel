<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use PHPUnit\Framework\Attributes\CoversNothing;
use Workbench\App\Models\Book;
use Workbench\App\Models\Shelf;
use Workbench\App\Providers\RelationOrderEloquentServiceProvider;
use Workbench\App\RelationOrder\Fixtures;

/**
 * {@see RelationOrderConformanceTestCase} against the reference Eloquent provider over
 * in-memory SQLite. `picks` reads by its pivot rank, and `classics` by its relation
 * method's `orderBy` ahead of the books' title default, while `books` has its own default
 * replace its method's order.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentRelationOrderConformanceTest extends RelationOrderConformanceTestCase
{
    protected function conformanceServiceProvider(): string
    {
        return RelationOrderEloquentServiceProvider::class;
    }

    protected function expectedPicks(): array
    {
        return ['4', '1', '2'];
    }

    protected function expectedClassics(): array
    {
        return ['1', '3', '4'];
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
        foreach (Fixtures::shelves() as $id => $row) {
            $shelf = Shelf::query()->create(['id' => $id, 'name' => $row['name']]);
            $shelf->favourites()->attach($row['favourites']);
            foreach ($row['picks'] as $bookId => $rank) {
                $shelf->picks()->attach($bookId, ['rank' => $rank]);
            }
        }
        foreach (Fixtures::books() as $id => $row) {
            Book::query()->create(['id' => $id, 'title' => $row['title'], 'year' => $row['year'], 'genre' => $row['genre'], 'shelf_id' => $row['shelf']]);
        }
    }
}
