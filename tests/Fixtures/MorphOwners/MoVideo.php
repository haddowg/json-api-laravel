<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphOwners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Declares no relation to the notes it owns, which are read through their polymorphic owner,
 * and a native `morphMany` of the notes it wrote.
 *
 * @property int    $id
 * @property string $title
 */
final class MoVideo extends Model
{
    public $timestamps = false;

    protected $table = 'mo_videos';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return MorphMany<MoNote, $this>
     */
    public function authoredNotes(): MorphMany
    {
        return $this->morphMany(MoNote::class, 'author');
    }
}
