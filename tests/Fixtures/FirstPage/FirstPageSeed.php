<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

use Illuminate\Support\Facades\DB;

/**
 * The Symfony bundle's twin seed. Board 1 holds cards 1 to 5 and labels 1 to 3, board 2 holds
 * card 6 and label 2. The desk holds cards 1 to 5, updated so that newest first they run 3, 5,
 * 1, 4, 2. Card 1 has comments 1 to 3, card 2 comment 4 and card 3 comments 5 and 6. Board 1
 * tags label 3, card 2, label 1 and card 5, in that order.
 */
final class FirstPageSeed
{
    /**
     * Each card's title, day of January it was last updated and board.
     */
    private const array CARDS = [
        1 => ['One', '03', 1],
        2 => ['Two', '01', 1],
        3 => ['Three', '05', 1],
        4 => ['Four', '02', 1],
        5 => ['Five', '04', 1],
        6 => ['Six', '06', 2],
    ];

    /**
     * Each comment's card.
     */
    private const array COMMENTS = [1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 3, 6 => 3];

    private const array LABELS = [1 => 'red', 2 => 'green', 3 => 'blue'];

    /**
     * Each board's labels.
     */
    private const array BOARD_LABELS = [1 => [1, 2, 3], 2 => [2]];

    /**
     * @return list<array{0: string, 1: int}>
     */
    public static function taggedPairs(int $board): array
    {
        return $board === 1 ? [['fp-labels', 3], ['fp-cards', 2], ['fp-labels', 1], ['fp-cards', 5]] : [];
    }

    public static function updatedAt(int $card): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-' . self::CARDS[$card][1] . 'T00:00:00+00:00');
    }

    /**
     * Inserts the seed into the Eloquent tables.
     */
    public static function insert(): void
    {
        DB::table('fp_desks')->insert(['id' => 1, 'name' => 'Front desk']);
        DB::table('fp_boards')->insert([['id' => 1, 'name' => 'Roadmap'], ['id' => 2, 'name' => 'Backlog']]);
        foreach (self::LABELS as $id => $name) {
            DB::table('fp_labels')->insert(['id' => $id, 'name' => $name]);
        }
        foreach (self::BOARD_LABELS as $board => $labels) {
            foreach ($labels as $label) {
                DB::table('fp_board_label')->insert(['board_id' => $board, 'label_id' => $label]);
            }
        }
        foreach (self::CARDS as $id => [$title, , $board]) {
            DB::table('fp_cards')->insert([
                'id' => $id,
                'title' => $title,
                'updatedAt' => self::updatedAt($id)->format('Y-m-d H:i:s'),
                'board_id' => $board,
                'desk_id' => $board === 1 ? 1 : null,
            ]);
        }
        foreach (self::COMMENTS as $id => $card) {
            DB::table('fp_comments')->insert(['id' => $id, 'body' => 'Comment ' . $id, 'card_id' => $card]);
        }
    }

    /**
     * The seed as in-memory records, by type and id.
     *
     * @return array<string, array<array-key, object>>
     */
    public static function records(): array
    {
        $desk = new FpDeskRecord('1', 'Front desk');
        $boards = ['1' => new FpBoardRecord('1', 'Roadmap'), '2' => new FpBoardRecord('2', 'Backlog')];

        $labels = [];
        foreach (self::LABELS as $id => $name) {
            $labels[(string) $id] = new FpLabelRecord((string) $id, $name);
        }
        foreach (self::BOARD_LABELS as $board => $ids) {
            foreach ($ids as $label) {
                $boards[(string) $board]->labels[] = $labels[(string) $label];
            }
        }

        $cards = [];
        foreach (self::CARDS as $id => [$title, , $board]) {
            $card = new FpCardRecord((string) $id, $title, self::updatedAt($id), $boards[(string) $board]);
            $cards[(string) $id] = $card;
            $boards[(string) $board]->cards[] = $card;
            if ($board === 1) {
                $desk->cards[] = $card;
            }
        }

        $comments = [];
        foreach (self::COMMENTS as $id => $card) {
            $comment = new FpCommentRecord((string) $id, 'Comment ' . $id);
            $comments[(string) $id] = $comment;
            if (isset($cards[(string) $card])) {
                $cards[(string) $card]->comments[] = $comment;
            }
        }

        return ['fp-desks' => ['1' => $desk], 'fp-boards' => $boards, 'fp-cards' => $cards, 'fp-comments' => $comments, 'fp-labels' => $labels];
    }
}
