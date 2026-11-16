<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The resource the gate-order suite writes, over Eloquent.
 *
 * @property int         $id
 * @property string      $name
 * @property string      $owner
 * @property int|null    $partner_id
 * @property int|null    $parent_id
 */
final class GateWidget extends Model
{
    public $timestamps = false;

    protected $table = 'gate_widgets';

    protected $guarded = [];

    /**
     * @return BelongsTo<GateWidget, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(self::class, 'partner_id');
    }

    /**
     * @return HasMany<GateWidget, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
