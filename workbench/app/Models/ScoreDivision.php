<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ScoreDivision extends Model
{
    protected $table = 'score_divisions';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<ScoreTeam, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(ScoreTeam::class, 'score_division_id');
    }
}
