<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Workbench\App\KeysetSorts\ScoredTeam;

/**
 * A team for the cursor-sort cases. It has no members relation, so its top score is only
 * a subquery away.
 *
 * @property int      $id
 * @property ?int     $base
 * @property ?int     $bonus
 */
final class ScoreTeam extends Model implements ScoredTeam
{
    protected $table = 'score_teams';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    public function base(): ?int
    {
        return $this->base;
    }

    public function bonus(): ?int
    {
        return $this->bonus;
    }

    public function scores(): array
    {
        throw new \LogicException('A team model does not load its members; the cursor reads the top score from the query.');
    }
}
