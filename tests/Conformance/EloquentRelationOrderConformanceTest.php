<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Conformance;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function aCursorPageReadsInThePivotOrder(): void
    {
        $first = $this->get('/api/shelves/1/rankedPicks', ['Accept' => self::MEDIA_TYPE]);
        $first->assertOk();
        /** @var list<array{id: string}> $data */
        $data = $first->json('data');
        self::assertSame(['4', '1'], \array_column($data, 'id'));

        $next = $first->json('links.next.href');
        self::assertIsString($next);
        /** @var list<array{id: string}> $rest */
        $rest = $this->get($next, ['Accept' => self::MEDIA_TYPE])->json('data');
        self::assertSame(['2'], \array_column($rest, 'id'));
    }

    #[Test]
    #[Group('spec:profiles')]
    public function aRelationshipQueriesCursorWindowReadsInThePivotOrder(): void
    {
        $response = $this->get(
            '/api/shelves/1?include=rankedPicks&relatedQuery[rankedPicks][filter][genre]=sf',
            ['Accept' => self::MEDIA_TYPE . ';profile="https://haddowg.github.io/json-api/profiles/relationship-queries/"'],
        );
        $response->assertOk();

        /** @var list<array{id: string}> $linkage */
        $linkage = $response->json('data.relationships.rankedPicks.data');
        self::assertSame(['4', '1'], \array_column($linkage, 'id'));
    }

    #[Test]
    public function orderedLinkageIsOneQueryPerRelationForAPage(): void
    {
        DB::enableQueryLog();
        $this->get('/api/shelves?include=books', ['Accept' => self::MEDIA_TYPE])->assertOk();

        $queries = \array_column(DB::getQueryLog(), 'query');
        $books = \array_filter($queries, static fn(string $query): bool => \str_contains($query, 'from "ro_books"') && !\str_contains($query, 'ro_shelf_'));
        $favourites = \array_filter($queries, static fn(string $query): bool => \str_contains($query, '"ro_shelf_favourite"') && !\str_contains($query, 'year'));
        self::assertCount(1, $books, \implode("\n", $queries));
        self::assertCount(1, $favourites, \implode("\n", $queries));
    }
}
