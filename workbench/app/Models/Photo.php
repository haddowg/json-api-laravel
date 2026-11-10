<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $caption
 */
final class Photo extends Model
{
    protected $table = 'lr_photos';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
