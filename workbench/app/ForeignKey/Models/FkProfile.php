<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An `fk-profiles` row, at most one a user.
 */
final class FkProfile extends Model
{
    public $timestamps = false;

    protected $table = 'fk_profiles';

    protected $guarded = [];
}
