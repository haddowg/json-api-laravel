<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $title
 */
final class Note extends Model
{
    protected $table = 'lr_notes';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
