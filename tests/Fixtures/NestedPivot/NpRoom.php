<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\NestedPivot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class NpRoom extends Model
{
    protected $table = 'np_rooms';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<NpShelf, $this>
     */
    public function shelves(): HasMany
    {
        return $this->hasMany(NpShelf::class, 'room_id');
    }
}
