<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int      $id
 * @property string   $title
 * @property int|null $queue_id
 * @property int|null $slot
 */
final class Song extends Model
{
    protected $table = 'pq_songs';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
