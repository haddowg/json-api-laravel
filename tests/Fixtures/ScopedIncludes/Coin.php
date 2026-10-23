<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ScopedIncludes;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int    $id
 * @property int    $vault_id
 * @property string $label
 * @property bool   $hidden
 */
final class Coin extends Model
{
    protected $table = 'si_coins';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $casts = ['hidden' => 'boolean'];

    /**
     * @return BelongsToMany<Mark, $this>
     */
    public function marks(): BelongsToMany
    {
        return $this->belongsToMany(Mark::class, 'si_coin_mark', 'coin_id', 'mark_id')->withPivot('position');
    }
}
