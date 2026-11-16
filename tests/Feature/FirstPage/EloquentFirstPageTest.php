<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\FirstPage;

use haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage\FirstPageSeed;
use haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage\FpCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

final class EloquentFirstPageTest extends FirstPageTestCase
{
    protected function store(): string
    {
        return 'eloquent';
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
        Schema::create('fp_desks', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('fp_boards', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('fp_labels', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('fp_board_label', static function (Blueprint $table): void {
            $table->unsignedInteger('board_id');
            $table->unsignedInteger('label_id');
        });
        Schema::create('fp_cards', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->dateTime('updatedAt');
            $table->unsignedInteger('board_id')->nullable();
            $table->unsignedInteger('desk_id')->nullable();
        });
        Schema::create('fp_comments', static function (Blueprint $table): void {
            $table->increments('id');
            $table->string('body');
            $table->unsignedInteger('card_id')->nullable();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        FirstPageSeed::insert();
    }

    #[Test]
    public function aCollectionIncludeReadsEachRelationsFirstPagesInOneBoundedQuery(): void
    {
        $statements = $this->statements('/fp-boards?include=cards.comments,labels&fields[fp-boards]=cards,featured,labels');

        // `cards` and `featured` read the same association with the same page, so they share
        // one bounded read.
        $cards = $this->selectsFrom($statements, 'fp_cards');
        self::assertCount(1, $cards, \implode("\n", $cards));
        self::assertCount(1, $this->windowed($cards));

        $comments = $this->selectsFrom($statements, 'fp_comments');
        self::assertCount(1, $comments, \implode("\n", $comments));
        self::assertCount(1, $this->windowed($comments));

        $labels = $this->selectsFrom($statements, 'fp_labels');
        self::assertCount(1, $labels, \implode("\n", $labels));
        self::assertCount(1, $this->windowed($labels));
    }

    #[Test]
    public function aGlobalScopeNarrowsTheFirstPageInsideItsOneBoundedQuery(): void
    {
        FpCard::addGlobalScope('visible', static fn(Builder $query): Builder => $query->where('fp_cards.id', '!=', 1));

        try {
            $statements = $this->statements('/fp-boards?include=cards&fields[fp-boards]=cards');
            $document = $this->fetch('/fp-boards?include=cards');
            $related = $this->fetch('/fp-boards/1/cards');
        } finally {
            FpCard::clearBootedModels();
        }

        $cards = $this->selectsFrom($statements, 'fp_cards');
        self::assertCount(1, $cards, \implode("\n", $cards));
        self::assertCount(1, $this->windowed($cards));
        self::assertStringContainsString('"fp_cards"."id" != ?', $cards[0]);

        self::assertSame(['2', '3'], \data_get($document, 'data.0.relationships.cards.data.*.id'));
        self::assertSame(['2', '3'], \data_get($related, 'data.*.id'));
    }

    #[Test]
    public function aRelationMethodsOrderCutsTheFirstPageWithNoDefaultSort(): void
    {
        $newest = \data_get($this->fetch('/fp-boards/1?include=newestCards'), 'data.relationships.newestCards');
        self::assertIsArray($newest);

        // Newest first: Three, then Five.
        self::assertSame(['3', '5'], \data_get($newest, 'data.*.id'));
        self::assertSame(['3', '5'], \data_get($this->fetch('/fp-boards/1/newestCards'), 'data.*.id'));

        $next = \data_get($newest, 'links.next.href');
        self::assertIsString($next);
        self::assertSame(['1', '4'], \data_get($this->fetch(\substr($next, \strlen(self::BASE_URI))), 'data.*.id'));
    }

    #[Test]
    public function aSingletonsCursorPageIsOneBoundedQuery(): void
    {
        $cards = $this->selectsFrom($this->statements('/desk?include=cards'), 'fp_cards');

        self::assertCount(1, $cards, \implode("\n", $cards));
        self::assertCount(1, $this->windowed($cards));
    }

    #[Test]
    public function aPivotRelationsFirstCursorPagesAreOneBoundedQuery(): void
    {
        $labels = $this->selectsFrom($this->statements('/fp-boards?include=cursorLabels'), 'fp_labels');

        self::assertCount(1, $labels, \implode("\n", $labels));
        self::assertCount(1, $this->windowed($labels));
    }

    #[Test]
    public function aPivotRelationsFirstCursorPageIsTheOneItsRelatedEndpointServes(): void
    {
        $boards = $this->fetch('/fp-boards?include=cursorLabels')['data'] ?? null;
        self::assertIsArray($boards);

        foreach ($boards as $board) {
            self::assertIsArray($board);
            $id = $board['id'] ?? null;
            self::assertIsString($id);
            $relationship = \data_get($board, 'relationships.cursorLabels');
            self::assertIsArray($relationship);

            $related = $this->fetch('/fp-boards/' . $id . '/cursorLabels');
            self::assertSame(\data_get($related, 'data.*.id'), \data_get($relationship, 'data.*.id'), 'board ' . $id);

            $next = \data_get($relationship, 'links.next.href');
            $relatedNext = \data_get($related, 'links.next.href');
            if ($relatedNext === null) {
                self::assertNull($next, 'board ' . $id);

                continue;
            }
            self::assertIsString($next);
            self::assertIsString($relatedNext);
            self::assertSame(
                \data_get($this->fetch(\substr($relatedNext, \strlen(self::BASE_URI))), 'data.*.id'),
                \data_get($this->fetch(\substr($next, \strlen(self::BASE_URI))), 'data.*.id'),
                'board ' . $id,
            );
        }
    }

    /**
     * The SQL `$path` runs.
     *
     * @return list<string>
     */
    private function statements(string $path): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->fetch($path);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return \array_values(\array_map(static fn(array $entry): string => (string) $entry['query'], $log));
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function windowed(array $statements): array
    {
        return \array_values(\array_filter($statements, static fn(string $sql): bool => \str_contains($sql, 'row_number() over')));
    }

    /**
     * @param list<string> $statements
     *
     * @return list<string>
     */
    private function selectsFrom(array $statements, string $table): array
    {
        return \array_values(\array_filter(
            $statements,
            static fn(string $sql): bool => \str_starts_with(\ltrim(\strtolower($sql)), 'select') && \str_contains(\strtolower($sql), 'from "' . $table . '"'),
        ));
    }
}
