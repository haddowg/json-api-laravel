<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Mutations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The writable Eloquent `posts` model backing the relationship-MUTATION feature matrix: an
 * owner-side to-one FK ({@see author()}, {@see sponsor()}, {@see moderator()}), a polymorphic
 * to-one ({@see feature()}), and two join-table to-manys ({@see tags()}, {@see pinnedTags()}).
 * The relation method IS the JSON:API relation's `column ?? name`.
 *
 * @property int         $id
 * @property string      $title
 * @property int|null    $author_id
 * @property int|null    $sponsor_id
 * @property int|null    $moderator_id
 * @property int|null    $feature_id
 * @property string|null $feature_type
 */
final class Post extends Model
{
    protected $table = 'mut_posts';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Author, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    /**
     * @return BelongsTo<Author, $this>
     */
    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'sponsor_id');
    }

    /**
     * @return BelongsTo<Author, $this>
     */
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'moderator_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function feature(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'mut_post_tag', 'post_id', 'tag_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function pinnedTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'mut_pinned_tag', 'post_id', 'tag_id');
    }
}
