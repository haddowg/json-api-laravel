<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * A user row as the `accounts` singleton sees it. Its global scope shows every query only
 * the signed-in user's row, and a guest none: a singleton's links all say `/me`, so its data
 * layer must never expose anyone else's account.
 *
 * @property int    $id
 * @property string $email
 * @property string $display_name
 */
final class Account extends Model
{
    protected $table = 'mc_users';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::addGlobalScope('own_account', static function (Builder $query): void {
            $id = Auth::id();
            if ($id === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereKey($id);
        });
    }

    /**
     * @return HasMany<Playlist, $this>
     */
    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class, 'owner_id');
    }
}
