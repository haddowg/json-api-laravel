<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int      $id
 * @property string   $label
 * @property int|null $featured_coin_id
 */
final class Vault extends Model
{
    protected $table = 'si_vaults';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<Coin, $this>
     */
    public function coins(): HasMany
    {
        return $this->hasMany(Coin::class, 'vault_id');
    }

    /**
     * @return BelongsTo<Coin, $this>
     */
    public function featuredCoin(): BelongsTo
    {
        return $this->belongsTo(Coin::class, 'featured_coin_id');
    }
}
