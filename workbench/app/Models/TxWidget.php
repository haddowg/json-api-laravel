<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The resource the write-transaction suite writes, over Eloquent.
 *
 * @property int    $id
 * @property string $name
 */
final class TxWidget extends Model
{
    public $timestamps = false;

    protected $table = 'tx_widgets';

    protected $guarded = [];

    /**
     * @return BelongsToMany<TxNote, $this>
     */
    public function notes(): BelongsToMany
    {
        return $this->belongsToMany(TxNote::class, 'tx_widget_note', 'tx_widget_id', 'tx_note_id');
    }
}
