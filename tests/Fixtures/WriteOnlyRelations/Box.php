<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int         $id
 * @property string      $label
 * @property int|null    $keeper_id
 * @property int|null    $secret_keeper_id
 */
final class Box extends Model
{
    protected $table = 'wo_boxes';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsTo<Keeper, $this>
     */
    public function keeper(): BelongsTo
    {
        return $this->belongsTo(Keeper::class);
    }

    /**
     * @return BelongsTo<Keeper, $this>
     */
    public function secretKeeper(): BelongsTo
    {
        return $this->belongsTo(Keeper::class, 'secret_keeper_id');
    }

    /**
     * @return BelongsToMany<Sticker, $this>
     */
    public function stickers(): BelongsToMany
    {
        return $this->belongsToMany(Sticker::class, 'wo_box_sticker', 'box_id', 'sticker_id')->withPivot('position');
    }

    /**
     * @return BelongsToMany<Sticker, $this>
     */
    public function badges(): BelongsToMany
    {
        return $this->belongsToMany(Sticker::class, 'wo_box_badge', 'box_id', 'sticker_id');
    }
}
