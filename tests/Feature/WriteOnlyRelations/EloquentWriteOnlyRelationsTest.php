<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\WriteOnlyRelations;

use haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations\Box;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Eloquent arm adds the query guard: a read never loads a write-only relation.
 *
 * @internal
 */
#[CoversNothing]
final class EloquentWriteOnlyRelationsTest extends WriteOnlyRelationsTestCase
{
    protected function store(): string
    {
        return 'eloquent';
    }

    #[Test]
    public function aReadIssuesNoQueryForAWriteOnlyRelation(): void
    {
        foreach (['/boxes', '/boxes/1'] as $path) {
            $queries = $this->queriesOf(fn(): TestResponse => $this->read($path));

            // One batch for the readable `keeper`, none for `secretKeeper`.
            self::assertCount(1, $this->touching($queries, 'wo_keepers'), $path);
            self::assertSame([], $this->touching($queries, 'wo_box_sticker'), $path);
            self::assertNotSame([], $this->touching($queries, 'wo_box_badge'), $path);
        }
    }

    #[Test]
    public function aRelationshipQueriesReadIssuesNoQueryForAWriteOnlyRelation(): void
    {
        $accept = self::MEDIA_TYPE . '; profile="' . self::RELATIONSHIP_QUERIES_PROFILE . '"';
        $queries = $this->queriesOf(fn(): TestResponse => $this->read('/boxes?relatedQuery[badges][sort]=label', ['Accept' => $accept]));

        self::assertCount(1, $this->touching($queries, 'wo_keepers'));
        self::assertSame([], $this->touching($queries, 'wo_box_sticker'));
    }

    #[Test]
    public function aReadSkipsAPredicateWriteOnlyRelationOnlyWhenItsPredicateHolds(): void
    {
        $queries = $this->queriesOf(fn(): TestResponse => $this->read('/boxes', ['X-Hide-Badges' => '1']));

        self::assertSame([], $this->touching($queries, 'wo_box_badge'));
    }

    protected function storedWriteOnlyMembers(string $boxId): array
    {
        $box = Box::query()->findOrFail($boxId);
        $stickers = $box->stickers()->pluck('wo_stickers.id')->map(static fn(mixed $id): string => \is_scalar($id) ? (string) $id : '')->all();

        return [$box->secret_keeper_id === null ? null : (string) $box->secret_keeper_id, \array_values($stickers)];
    }

    /**
     * @param \Closure(): mixed $work
     *
     * @return list<string>
     */
    private function queriesOf(\Closure $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $work();
        } finally {
            DB::disableQueryLog();
        }

        return \array_values(\array_map(static fn(array $entry): string => (string) $entry['query'], DB::getQueryLog()));
    }

    /**
     * @param list<string> $queries
     *
     * @return list<string>
     */
    private function touching(array $queries, string $table): array
    {
        return \array_values(\array_filter($queries, static fn(string $sql): bool => \str_contains($sql, '"' . $table . '"')));
    }
}
