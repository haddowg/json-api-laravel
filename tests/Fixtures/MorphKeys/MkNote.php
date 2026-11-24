<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A note about one target and pinned to others, each kept as a type and an id in a text
 * column that holds ids of every kind.
 *
 * @property int         $id
 * @property string|null $subject_type
 * @property string|null $subject_id
 */
final class MkNote extends Model
{
    protected $table = 'mk_notes';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<MkPin, $this>
     */
    public function pins(): HasMany
    {
        return $this->hasMany(MkPin::class, 'note_id');
    }
}
