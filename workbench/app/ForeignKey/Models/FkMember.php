<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An `fk-members` row on a team. Its team declares no inverse relation, so it reaches its members only through this row's `team`.
 */
final class FkMember extends Model
{
    public $timestamps = false;

    protected $table = 'fk_members';

    protected $guarded = [];

    /**
     * @return BelongsTo<FkTeam, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(FkTeam::class, 'team_id');
    }
}
