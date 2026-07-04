<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\EloquentRelations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * The Eloquent `blog_posts` model — the parent carrying every 3a relation cardinality the
 * reference provider exercises against real SQL (the Eloquent twin of the in-memory
 * {@see \haddowg\JsonApiLaravel\Tests\Fixtures\Relations\Post} POPO):
 *  - {@see author()} — a monomorphic to-one (belongsTo), null for an unowned post;
 *  - {@see tags()} — a `belongsToMany` with a `position` pivot column (the pivot-READ
 *    witness: {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider::fetchRelationshipPivot()});
 *  - {@see feature()} — a **polymorphic** to-one ({@see MorphTo}), an author OR a tag,
 *    resolved through the morph map ({@see EloquentBlogRelationsServiceProvider});
 *  - {@see related()} accessor — a **polymorphic** to-many (the over-parity headline):
 *    a mixed author + tag set merged from two {@see relatedAuthors()}/{@see relatedTags()}
 *    `morphedByMany` relations over ONE polymorphic pivot, read off the parent and windowed
 *    in PHP (Doctrine throws here; the Eloquent reference supports it).
 *
 * @property int      $id
 * @property string   $title
 * @property int|null $author_id
 * @property int|null $feature_id
 * @property string|null $feature_type
 */
final class Post extends Model
{
    protected $table = 'blog_posts';

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
        return $this->belongsTo(Author::class);
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'blog_post_tag', 'post_id', 'tag_id')
            ->withPivot('position');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function feature(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphToMany<Author, $this>
     */
    public function relatedAuthors(): MorphToMany
    {
        return $this->morphedByMany(Author::class, 'member', 'blog_post_members', 'post_id', 'member_id');
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function relatedTags(): MorphToMany
    {
        return $this->morphedByMany(Tag::class, 'member', 'blog_post_members', 'post_id', 'member_id');
    }

    /**
     * The mixed polymorphic `related` set — authors AND tags — merged from the two
     * `morphedByMany` relations sharing the one polymorphic pivot. Read by the `related`
     * relation's `extractUsing` closure (a native Eloquent to-many is single-class, so a
     * heterogeneous set is composed here).
     *
     * @return list<Author|Tag>
     */
    public function relatedMembers(): array
    {
        return [
            ...\array_values($this->relatedAuthors()->get()->all()),
            ...\array_values($this->relatedTags()->get()->all()),
        ];
    }
}
