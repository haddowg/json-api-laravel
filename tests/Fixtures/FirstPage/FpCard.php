<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FirstPage;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class FpCard extends Model
{
    protected $table = 'fp_cards';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['updatedAt' => 'immutable_datetime'];

    /**
     * @return BelongsTo<FpBoard, $this>
     */
    public function board(): BelongsTo
    {
        return $this->belongsTo(FpBoard::class, 'board_id');
    }

    /**
     * @return HasMany<FpComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(FpComment::class, 'card_id');
    }
}
