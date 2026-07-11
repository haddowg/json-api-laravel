<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Unit\DataProvider\Eloquent;

use haddowg\JsonApi\Collection\CollectionResult;
use haddowg\JsonApi\Operation\QueryParameters;
use haddowg\JsonApi\Pagination\OffsetWindow;
use haddowg\JsonApi\Request\JsonApiRequestInterface;
use haddowg\JsonApi\Resource\Field\HasMany;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApiLaravel\DataProvider\CollectionCriteria;
use haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider;
use haddowg\JsonApiLaravel\DataProvider\InMemoryDataProvider;
use haddowg\JsonApiLaravel\Tests\Eloquent\EloquentTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Domain\Album as DomainAlbum;
use Workbench\App\Domain\Artist as DomainArtist;
use Workbench\App\Models\Album;
use Workbench\App\Models\Artist;

/**
 * The ADR 0006 determinism REFEREE (PLAN's tie-break watch item): the Eloquent windowed batch's
 * SQL `groupLimit`/`ROW_NUMBER` push-down and the in-memory witness's `WindowExecutor` +
 * `withPkTiebreak` must return byte-identical pages when a relation ties on every requested sort
 * key. Both append the SAME final `id ASC` tiebreak — the SQL as the ROW_NUMBER `OVER (… ORDER
 * BY status, id)`, the witness as a synthetic PK sort — so a tie resolves identically on both.
 *
 * The children are seeded OUT of id order and fully tied on the sort key (`status`), so a stable
 * PHP sort alone would preserve insertion order and SQLite's partition scan is otherwise
 * unordered: only the appended primary-key tiebreak makes both id-ascending, and the assertion
 * pins that the TWO providers agree (a regression on either surfaces here).
 *
 * @internal
 */
#[CoversClass(EloquentDataProvider::class)]
#[CoversClass(InMemoryDataProvider::class)]
final class WindowedBatchDeterminismTest extends EloquentTestCase
{
    #[Test]
    public function theSqlPushDownAndTheWitnessAgreeByteForByteOnTiedSortKeys(): void
    {
        $this->seedTiedEloquent();

        $relation = HasMany::make('albums', 'albums')->build();
        $criteria = $this->tiedStatusWindow();
        $request = $this->createStub(JsonApiRequestInterface::class);

        // The Eloquent push-down over real SQLite.
        $eloquent = new EloquentDataProvider(['artists' => Artist::class, 'albums' => Album::class]);
        /** @var list<Artist> $eloquentParents */
        $eloquentParents = Artist::query()->whereIn('id', [500, 600])->orderBy('id')->get()->all();
        $eloquentBatch = $eloquent->fetchRelatedCollectionBatch('artists', $eloquentParents, $relation, $criteria, $request);

        // The in-memory witness over an object graph carrying the SAME ids/tied status.
        [$a, $b] = $this->tiedInMemoryArtists();
        $inMemory = new InMemoryDataProvider(
            'artists',
            ['500' => $a, '600' => $b],
            identify: static fn(object $parent): string => $parent instanceof DomainArtist ? $parent->id : '',
        );
        $inMemoryBatch = $inMemory->fetchRelatedCollectionBatch('artists', [$a, $b], $relation, $criteria, $request);

        // Parent A's albums (seeded 503, 501, 502) window to the id-ascending page 1 [501, 502]
        // on BOTH providers; parent B's (620, 610) to [610]. Byte-identical is the referee.
        self::assertSame(['501', '502'], $this->ids($eloquentBatch->for('500')));
        self::assertSame($this->ids($eloquentBatch->for('500')), $this->ids($inMemoryBatch->for('500')));

        self::assertSame(['610', '620'], $this->ids($inMemoryBatch->for('600')));
        self::assertSame($this->ids($eloquentBatch->for('600')), $this->ids($inMemoryBatch->for('600')));
    }

    /**
     * A windowed criteria sorting by the (fully tied) `status`, limit 2 — so only the appended
     * primary-key tiebreak orders each partition.
     */
    private function tiedStatusWindow(): CollectionCriteria
    {
        return new CollectionCriteria(
            new QueryParameters([], [], ['byStatus'], [], []),
            sorts: [SortByField::make('byStatus', 'status')],
            window: new OffsetWindow(0, 2),
        );
    }

    /**
     * Two artists (500, 600) whose albums are seeded OUT of id order and ALL share one status
     * (`archived`) — a fully-tied sort key. Album released_at is identical (out of the sort), so
     * the ONLY discriminator left is the primary-key tiebreak.
     */
    private function seedTiedEloquent(): void
    {
        Artist::query()->insert([
            ['id' => 500, 'name' => 'Alpha', 'slug' => 'alpha', 'track_count' => 0, 'created_at' => '2020-01-01 00:00:00'],
            ['id' => 600, 'name' => 'Beta', 'slug' => 'beta', 'track_count' => 0, 'created_at' => '2020-01-01 00:00:00'],
        ]);

        Album::query()->insert([
            ['id' => 503, 'artist_id' => 500, 'title' => 'A three', 'status' => 'archived', 'explicit' => false, 'released_at' => '2020-01-01 00:00:00'],
            ['id' => 501, 'artist_id' => 500, 'title' => 'A one', 'status' => 'archived', 'explicit' => false, 'released_at' => '2020-01-01 00:00:00'],
            ['id' => 502, 'artist_id' => 500, 'title' => 'A two', 'status' => 'archived', 'explicit' => false, 'released_at' => '2020-01-01 00:00:00'],
            ['id' => 620, 'artist_id' => 600, 'title' => 'B two', 'status' => 'archived', 'explicit' => false, 'released_at' => '2020-01-01 00:00:00'],
            ['id' => 610, 'artist_id' => 600, 'title' => 'B one', 'status' => 'archived', 'explicit' => false, 'released_at' => '2020-01-01 00:00:00'],
        ]);
    }

    /**
     * The identical object graph for the in-memory witness: the same album ids/tied status as
     * {@see seedTiedEloquent()}, in the same OUT-of-id insertion order.
     *
     * @return array{DomainArtist, DomainArtist}
     */
    private function tiedInMemoryArtists(): array
    {
        $a = new DomainArtist(id: '500', name: 'Alpha', slug: 'alpha', albums: [
            new DomainAlbum(id: '503', title: 'A three', status: 'archived'),
            new DomainAlbum(id: '501', title: 'A one', status: 'archived'),
            new DomainAlbum(id: '502', title: 'A two', status: 'archived'),
        ]);
        $b = new DomainArtist(id: '600', name: 'Beta', slug: 'beta', albums: [
            new DomainAlbum(id: '620', title: 'B two', status: 'archived'),
            new DomainAlbum(id: '610', title: 'B one', status: 'archived'),
        ]);

        return [$a, $b];
    }

    /**
     * The wire ids of a batch partition (Eloquent model key or in-memory POPO id), in order.
     *
     * @param CollectionResult<object> $result
     *
     * @return list<string>
     */
    private function ids(CollectionResult $result): array
    {
        $ids = [];
        foreach ($result->items as $item) {
            if ($item instanceof Album) {
                /** @var mixed $key */
                $key = $item->getKey();
                $ids[] = \is_scalar($key) ? (string) $key : '';

                continue;
            }

            self::assertInstanceOf(DomainAlbum::class, $item);
            $ids[] = $item->id;
        }

        return $ids;
    }
}
