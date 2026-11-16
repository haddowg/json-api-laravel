<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * A user's inbox, the singleton suite's resource over Eloquent. Its global scope shows each
 * caller only their own inbox, as a singleton's data layer must.
 *
 * @property int    $id
 * @property int    $owner_id
 * @property string $title
 */
final class Inbox extends Model
{
    public $timestamps = false;

    protected $table = 'inboxes';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('caller', static function (Builder $query): void {
            $query->where('owner_id', Auth::id() ?? 0);
        });
    }

    /**
     * @return HasMany<InboxItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InboxItem::class, 'inbox_id');
    }

    /**
     * @return BelongsToMany<InboxLabel, $this>
     */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(InboxLabel::class, 'inbox_label', 'inbox_id', 'inbox_label_id');
    }
}
