<?php

declare(strict_types=1);

namespace Workbench\App\LinkRows;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApi\Resource\Filter\Where;
use haddowg\JsonApi\Resource\Sort\SortByField;
use haddowg\JsonApi\Resource\Sort\SortDirective;
use Workbench\App\Models\Board as BoardModel;
use Workbench\App\Models\BoardItem;
use Workbench\App\Models\Note as NoteModel;

/**
 * A board whose `items` are notes and photos linked by its `boardItems` rows, declared by
 * their fields so the data layer queries and writes them, each with a position and a label,
 * `looseItems` reads the same rows through an `identifiedBy()` closure and writes them with
 * `fillUsing()`, and `pins` are notes read off a morph pivot through a closure; both are
 * served in memory.
 */
final class BoardResource extends AbstractResource
{
    public static string $type = 'boards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphToMany::make('items', ['notes', 'photos'])
                ->storedAs('boardItems')
                ->through(BoardItem::class)
                ->identifiedByRows(owner: 'board', type: 'item_type', id: 'item_id')
                ->withData()
                ->countable()
                ->fields(Integer::make('position'), Str::make('label')->nullable())
                ->withFilters(Where::make('label', 'pivot.label'))
                ->defaultSort(new SortDirective(SortByField::make('position'), descending: false)),
            MorphToMany::make('looseItems', ['notes', 'photos'])
                ->identifiedBy(static fn(mixed $board): array => self::looseItems($board))
                ->fields(Str::make('label')->nullable())
                ->fillUsing(static function (mixed $board, mixed $linkage, array $data): mixed {
                    \assert($linkage instanceof ToManyRelationship);
                    $mode = $data['mode'] ?? Mode::Replace;
                    \assert($mode instanceof Mode);
                    self::fillLooseItems($board, $linkage, $mode);

                    return $board;
                }),
            MorphToMany::make('pins', ['notes'])
                ->identifiedBy(static fn(mixed $board): array => self::pins($board))
                ->fields(Integer::make('rank'))
                ->defaultSort(new SortDirective(SortByField::make('rank'), descending: false)),
        ];
    }

    /**
     * The same rows as `items`, read through a closure.
     *
     * @return list<array{0: string, 1: int, 2: object}>
     */
    private static function looseItems(mixed $board): array
    {
        $rows = match (true) {
            $board instanceof Board => $board->boardItems,
            $board instanceof BoardModel => $board->boardItems->all(),
            default => [],
        };

        return \array_values(\array_map(static fn(BoardItem $row): array => [$row->item_type, $row->item_id, $row], $rows));
    }

    /**
     * Keeps a row per member, deleting the dropped ones and creating the new ones; the pivot
     * values a write sends land on the rows after it.
     */
    private static function fillLooseItems(mixed $board, ToManyRelationship $linkage, Mode $mode): void
    {
        $sent = [];
        foreach ($linkage->resourceIdentifiers as $identifier) {
            $sent[$identifier->type . ':' . $identifier->id] = [$identifier->type, (int) $identifier->id];
        }

        $kept = [];
        foreach (self::looseItems($board) as [$type, $id, $row]) {
            $named = isset($sent[$type . ':' . $id]);
            $drop = ($mode === Mode::Replace && !$named) || ($mode === Mode::Remove && $named);
            \assert($row instanceof BoardItem);
            if ($drop && $row->exists) {
                $row->delete();
            }
            if (!$drop) {
                $kept[] = $row;
            }
            unset($sent[$type . ':' . $id]);
        }

        $added = $mode === Mode::Remove ? [] : \array_values($sent);
        if ($board instanceof Board) {
            $board->boardItems = [...$kept, ...\array_map(static fn(array $pair): BoardItem => new BoardItem(['item_type' => $pair[0], 'item_id' => $pair[1], 'position' => 0]), $added)];
        } elseif ($board instanceof BoardModel) {
            foreach ($added as [$type, $id]) {
                $board->boardItems()->create(['item_type' => $type, 'item_id' => $id]);
            }
            $board->unsetRelation('boardItems');
        }
    }

    /**
     * @return list<array{0: string, 1: int, 2: object}>
     */
    private static function pins(mixed $board): array
    {
        if ($board instanceof Board) {
            return \array_map(static fn(Pin $pin): array => ['notes', $pin->note_id, $pin], $board->pins);
        }

        if (!$board instanceof BoardModel) {
            return [];
        }

        $pins = [];
        foreach ($board->pins as $note) {
            \assert($note instanceof NoteModel);
            $pivot = $note->getRelation('pivot');
            \assert(\is_object($pivot));
            $pins[] = ['notes', $note->id, $pivot];
        }

        return $pins;
    }
}
