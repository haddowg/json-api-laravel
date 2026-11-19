<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\AdditionalProperties;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\Str;

/**
 * A shelf's items pivot is open to any integer member, which its join row keeps in its
 * `extras` column.
 */
final class TrShelfResource extends AbstractResource
{
    public static string $type = 'tr-shelves';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('name'),
            BelongsToMany::make('items', 'tr-items')->fields(
                Integer::make('position')->build(),
                AdditionalProperties::make()->of(Integer::make('*'))->fillUsing(static function (ShelfItem $row, array $members): ShelfItem {
                    $row->extras = $members;

                    return $row;
                }),
            ),
        ];
    }
}
