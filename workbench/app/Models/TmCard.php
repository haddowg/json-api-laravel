<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card on a board.
 *
 * @property int      $id
 * @property string   $title
 * @property bool     $done
 * @property int|null $board_id
 */
final class TmCard extends Model
{
    public $timestamps = false;

    protected $table = 'tm_cards';

    protected $guarded = [];

    protected $casts = ['done' => 'boolean'];

    /**
     * @return BelongsTo<TmBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(TmBoard::class, 'board_id');
    }
}
