<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One resource an inbox item links to, as a morph alias and an id.
 *
 * @property int    $id
 * @property int    $item_id
 * @property string $linked_type
 * @property string $linked_id
 */

final class InboxLink extends Model
{
    protected $table = 'inb_links';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<InboxItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(InboxItem::class, 'item_id');
    }
}
