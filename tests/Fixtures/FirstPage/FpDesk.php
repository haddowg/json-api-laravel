<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FpDesk extends Model
{
    protected $table = 'fp_desks';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<FpCard, $this>
     */
    public function cards(): HasMany
    {
        return $this->hasMany(FpCard::class, 'desk_id');
    }
}
