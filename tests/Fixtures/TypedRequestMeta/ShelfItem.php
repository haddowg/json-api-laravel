<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A shelf's join row, whose `extras` column holds the members the pivot's
 * additionalProperties entry takes.
 *
 * @property array<string, mixed>|null $extras
 */
final class ShelfItem extends Pivot
{
    protected $table = 'tr_shelf_item';

    protected $casts = ['extras' => 'array'];
}
