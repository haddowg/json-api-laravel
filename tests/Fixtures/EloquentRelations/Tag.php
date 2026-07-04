<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations;

use Illuminate\Database\Eloquent\Model;

/**
 * The Eloquent `blog_tags` model — a leaf type, the target of a {@see Post}'s `tags`
 * belongsToMany (with a `position` pivot column, the pivot-READ witness) and the other
 * member of its polymorphic `feature` / `related` relations. Morph alias `blog_tag`, again
 * distinct from the JSON:API type `tags` (blueprint §3g).
 *
 * @property int    $id
 * @property string $label
 */
final class Tag extends Model
{
    protected $table = 'blog_tags';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
