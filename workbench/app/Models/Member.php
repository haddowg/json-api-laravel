<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Member extends Model
{
    protected $table = 'rr_members';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
