<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Player extends Model
{
    protected $table = 'rb_players';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
