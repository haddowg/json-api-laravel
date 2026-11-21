<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\TypedRequestMeta;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A note holding items, whose writes declare their request meta and whose resource
 * objects declare their meta.
 *
 * @property int         $id
 * @property string      $title
 * @property int         $revision
 * @property string|null $source
 * @property string|null $created_with
 */
final class Note extends Model
{
    public $timestamps = false;

    protected $table = 'tr_notes';

    protected $guarded = [];

    protected $attributes = ['revision' => 1];

    protected $casts = ['revision' => 'integer'];

    /**
     * @return BelongsToMany<Item, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'tr_note_item', 'note_id', 'item_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'lead_id');
    }
}
