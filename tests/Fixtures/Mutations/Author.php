<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The writable Eloquent `authors` model: the target of the posts' owner-side to-ones and the
 * parent of the inverse-FK {@see posts()} to-many (the HasMany FK-move arm of the persister).
 *
 * @property int    $id
 * @property string $name
 */
final class Author extends Model
{
    protected $table = 'mut_authors';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }
}
