<?php

declare(strict_types=1);

namespace Workbench\App\ForeignKey\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An `fk-items` row, whose `owner_key` holds its user's id.
 */
final class FkItem extends Model
{
    public $timestamps = false;

    protected $table = 'fk_items';

    protected $guarded = [];
}
