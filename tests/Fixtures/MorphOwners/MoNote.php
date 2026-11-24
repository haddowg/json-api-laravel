<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A note owned by a post or a video through two columns, and written by one through Eloquent's
 * own `morphTo`, hidden when its body is `hidden`.
 *
 * @property int         $id
 * @property string      $body
 * @property string|null $owner_type
 * @property int|null    $owner_id
 */
final class MoNote extends Model
{
    public $timestamps = false;

    protected $table = 'mo_notes';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo('author');
    }

    /**
     * A plain `belongsTo`, which a `MorphMany` may not name.
     *
     * @return BelongsTo<MoPost, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(MoPost::class, 'author_id');
    }

    protected static function booted(): void
    {
        static::addGlobalScope('visible', static fn(Builder $query) => $query->where('mo_notes.body', '<>', 'hidden'));
    }
}
