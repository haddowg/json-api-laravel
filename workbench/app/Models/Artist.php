<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The `artists` Eloquent model backing the reference {@see \haddowg\JsonApiLaravel\DataProvider\Eloquent\EloquentDataProvider}.
 * It shares the {@see \Workbench\App\JsonApi\ArtistResource} declaration with the
 * in-memory POPO: the resource's `storedAs()` columns are this model's snake_case cast
 * attributes, so serialization reads the same wire shape from either provider.
 *
 * `$timestamps = false` — `created_at` is a domain attribute, not Eloquent's managed
 * timestamp (there is no `updated_at`), so it is a plain cast column.
 *
 * @property int $id
 */
final class Artist extends Model
{
    protected $table = 'artists';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'track_count' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * The artist's albums — declared for the relationship-existence filters
     * (`WhereHas`/`WhereDoesntHave`/`WhereThrough`); the resource-level relation
     * declaration is Phase 3.
     *
     * @return HasMany<Album, $this>
     */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }
}
