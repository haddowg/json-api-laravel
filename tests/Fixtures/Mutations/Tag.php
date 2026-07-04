<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

use Illuminate\Database\Eloquent\Model;

/**
 * The writable Eloquent `tags` model — the far member of the join-table to-manys
 * ({@see Post::tags()}, {@see Post::pinnedTags()}) and a polymorphic `feature` member.
 *
 * @property int    $id
 * @property string $label
 */
final class Tag extends Model
{
    protected $table = 'mut_tags';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
