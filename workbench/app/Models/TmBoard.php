<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A board of cards, with one pinned card.
 *
 * @property int      $id
 * @property string   $name
 * @property int|null $pinned_id
 */
final class TmBoard extends Model
{
    public $timestamps = false;

    protected $table = 'tm_boards';

    protected $guarded = [];

    /**
     * @return HasMany<TmCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(TmCard::class, 'board_id');
    }

    /**
     * @return BelongsTo<TmCard, $this>
     */
    public function pinned(): BelongsTo
    {
        return $this->belongsTo(TmCard::class, 'pinned_id');
    }
}
