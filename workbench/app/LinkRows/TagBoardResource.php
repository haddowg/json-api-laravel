<?php

declare(strict_types=1);

namespace Workbench\App\LinkRows;

use haddowg\JsonApi\Hydrator\Relationship\ToManyRelationship;
use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\AdditionalPropertiesBuilder;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Mode;
use haddowg\JsonApi\Resource\Field\MorphToMany;
use haddowg\JsonApi\Resource\Field\Str;
use Workbench\App\Models\BoardItem;

/**
 * The boards again, whose link rows take a `tag` through an open pivot: `tagged` declares
 * its link row fields, and `looseTagged` reads and writes the same rows through closures and
 * declares nothing but the entry. The entry keeps the tag in each row's label.
 */
final class TagBoardResource extends AbstractResource
{
    public static string $type = 'tag-boards';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            MorphToMany::make('tagged', ['notes', 'photos'])
                ->storedAs('boardItems')
                ->through(BoardItem::class)
                ->identifiedByRows(owner: 'board', type: 'item_type', id: 'item_id')
                ->fields(Integer::make('position'), self::tag()),
            MorphToMany::make('looseTagged', ['notes', 'photos'])
                ->identifiedBy(static fn(mixed $board): array => BoardResource::looseItems($board))
                ->fields(self::tag())
                ->fillUsing(static function (mixed $board, mixed $linkage, array $data): mixed {
                    \assert($linkage instanceof ToManyRelationship);
                    $mode = $data['mode'] ?? Mode::Replace;
                    \assert($mode instanceof Mode);
                    BoardResource::fillLooseItems($board, $linkage, $mode);

                    return $board;
                }),
        ];
    }

    private static function tag(): AdditionalPropertiesBuilder
    {
        return AdditionalProperties::make()->of(Str::make('*'))
            ->fillUsing(static function (BoardItem $row, array $members): BoardItem {
                $tag = $members['tag'] ?? null;
                $row->label = \is_string($tag) ? $tag : null;

                return $row;
            });
    }
}
