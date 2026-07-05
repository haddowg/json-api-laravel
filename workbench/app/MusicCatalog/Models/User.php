<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The `users` Eloquent model (storage table `mc_users`) — the admin-only type and the
 * backing row for the curated `public-profiles` view. `preferences` is a dynamic-key JSON
 * object (an array cast); `is_admin` gates the playlist API policy.
 *
 * @property int                             $id
 * @property string                          $email
 * @property string                          $display_name
 * @property \Illuminate\Support\Carbon|null $birth_date
 * @property array<string, mixed>|null       $preferences
 * @property string|null                     $last_seen_ip
 * @property string|null                     $password
 * @property bool                            $is_admin
 */
final class User extends Model
{
    protected $table = 'mc_users';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'birth_date' => 'date',
        'preferences' => 'array',
        'is_admin' => 'boolean',
    ];

    /**
     * @return HasMany<Playlist, $this>
     */
    public function playlists(): HasMany
    {
        return $this->hasMany(Playlist::class, 'owner_id');
    }

    /**
     * @return HasOne<Library, $this>
     */
    public function library(): HasOne
    {
        return $this->hasOne(Library::class, 'owner_id');
    }
}
