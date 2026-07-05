<?php

declare(strict_types=1);

namespace Workbench\App\MusicCatalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The `favorites` Eloquent model (storage table `mc_favorites`) — the polymorphic to-one
 * witness. `favoritable` is a native Eloquent {@see morphTo} spanning tracks/albums/artists
 * (the stored `favoritable_type` is a morph alias mapped in the wiring provider); an empty
 * target renders `data: null`.
 *
 * @property int                             $id
 * @property int|null                        $user_id
 * @property \Illuminate\Support\Carbon|null $favorited_at
 * @property string|null                     $favoritable_type
 * @property string|null                     $favoritable_id
 */
final class Favorite extends Model
{
    protected $table = 'mc_favorites';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'favorited_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function favoritable(): MorphTo
    {
        return $this->morphTo();
    }
}
