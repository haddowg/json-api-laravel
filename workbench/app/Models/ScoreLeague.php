<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class ScoreLeague extends Model
{
    protected $table = 'score_leagues';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return BelongsToMany<ScoreTeam, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(ScoreTeam::class, 'score_league_team', 'score_league_id', 'score_team_id');
    }
}
