<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\MorphKeys;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $id
 * @property int    $note_id
 * @property string $pin_type
 * @property string $pin_id
 */
final class MkPin extends Model
{
    protected $table = 'mk_pins';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<MkNote, $this>
     */
    public function note(): BelongsTo
    {
        return $this->belongsTo(MkNote::class, 'note_id');
    }
}
