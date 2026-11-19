<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\StaticAnalysis;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The Eloquent model the hook fixtures type their closures on, declared the way an
 * application declares its own.
 *
 * @property int                       $id
 * @property string                    $title
 * @property bool                      $locked
 * @property array<string, mixed>|null $credits
 * @property HookAlbum|null            $parent
 */
final class HookAlbum extends Model
{
    /**
     * @return BelongsTo<HookAlbum, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class);
    }

    /**
     * @return HasMany<HookAlbum, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class);
    }
}
