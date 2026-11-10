<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationOrderCheck;

use haddowg\JsonApi\Resource\AbstractResource;
use haddowg\JsonApi\Resource\Field\BelongsToMany;
use haddowg\JsonApi\Resource\Field\Id;
use haddowg\JsonApi\Resource\Field\Integer;

/**
 * A bin whose `slots` keep a position on their join rows.
 */
final class BinResource extends AbstractResource
{
    public static string $type = 'bins';

    public function fields(): array
    {
        return [
            Id::make(),
            BelongsToMany::make('slots', 'entries')
                ->fields(Integer::make('position'))
                ->positionedBy('position'),
        ];
    }
}
