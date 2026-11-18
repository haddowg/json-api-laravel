<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An `fk-teams` row, with no relation to its members.
 */
final class FkTeam extends Model
{
    public $timestamps = false;

    protected $table = 'fk_teams';

    protected $guarded = [];
}
