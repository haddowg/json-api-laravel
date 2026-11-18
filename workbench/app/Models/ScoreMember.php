<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

final class ScoreMember extends Model
{
    protected $table = 'score_members';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
