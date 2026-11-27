<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\Accessor;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\DateTime;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;
use haddowg\JsonApi\Resource\Field\PivotEdge;
use haddowg\JsonApi\Resource\Field\Str;
use haddowg\JsonApiLaravel\Attribute\AsJsonApiResource;

/**
 * `records` pairs stored pivot columns with a computed `label`; `labelledRecords` reads the
 * same join with only the computed field.
 */
#[AsJsonApiResource(readOnly: true)]
final class NpCrateResource extends AbstractResource
{
    public static string $type = 'npCrates';

    public function fields(): array
    {
        return [
            Id::make(),
            Str::make('title'),
            BelongsToMany::make('records', 'npRecords')->fields(
                Integer::make('position'),
                DateTime::make('addedAt')->storedAs('added_at'),
                Str::make('label')->computedUsing(self::label(...)),
            ),
            BelongsToMany::make('labelledRecords', 'npRecords')
                ->storedAs('records')
                ->fields(Str::make('label')->computedUsing(self::label(...))),
        ];
    }

    private static function label(mixed $edge): string
    {
        if (!$edge instanceof PivotEdge) {
            return '';
        }
        $crate = Accessor::get($edge->parent, 'title');
        $record = Accessor::get($edge->related, 'title');

        return (\is_string($crate) ? $crate : '') . ' / ' . (\is_string($record) ? $record : '');
    }
}
