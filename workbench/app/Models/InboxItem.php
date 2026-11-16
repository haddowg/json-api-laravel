<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An item in a user's inbox.
 *
 * @property int    $id
 * @property int    $inbox_id
 * @property string $subject
 */
final class InboxItem extends Model
{
    public $timestamps = false;

    protected $table = 'inbox_items';

    protected $guarded = [];

    /**
     * @return BelongsTo<Inbox, $this>
     */
    public function inbox(): BelongsTo
    {
        return $this->belongsTo(Inbox::class, 'inbox_id');
    }
}
