<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations;

use Illuminate\Database\Eloquent\Model;

/**
 * The Eloquent `blog_authors` model — a leaf type, a member of a {@see Post}'s polymorphic
 * `feature` / `related` relations and the target of its monomorphic `author` belongsTo. Its
 * morph alias (`blog_author`, registered in {@see EloquentBlogRelationsServiceProvider}) is
 * deliberately distinct from its JSON:API type (`authors`), proving the morph-alias ↔
 * JSON:API-type decoupling (blueprint §3g).
 *
 * @property int    $id
 * @property string $name
 */
final class Author extends Model
{
    protected $table = 'blog_authors';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
