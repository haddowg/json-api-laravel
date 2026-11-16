<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An inbox item about an rfp or a campaign, its subject a native morphTo stored under the
 * morph map's aliases, with read and archived state as nullable timestamps.
 *
 * @property int                             $id
 * @property string                          $title
 * @property int|null                        $folder_id
 * @property string|null                     $subject_type
 * @property string|null                     $subject_id
 * @property \Illuminate\Support\Carbon|null $read_ts
 * @property \Illuminate\Support\Carbon|null $archived_ts
 */

final class InboxItem extends Model
{
    protected $table = 'inb_items';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'read_ts' => 'datetime',
        'archived_ts' => 'datetime',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<InboxLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(InboxLink::class, 'item_id');
    }
}
