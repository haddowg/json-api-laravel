<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link row on a board, on the Eloquent provider and, unsaved, on the in-memory witness.
 *
 * @property int         $id
 * @property int         $board_id
 * @property string      $item_type
 * @property int         $item_id
 * @property int         $position
 * @property string|null $label
 */
final class BoardItem extends Model
{
    protected $table = 'lr_board_items';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = ['item_id' => 'integer', 'position' => 'integer'];

    /**
     * @return BelongsTo<Board, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class, 'board_id');
    }
}
