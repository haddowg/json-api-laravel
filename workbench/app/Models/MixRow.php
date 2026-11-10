<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int      $id
 * @property int      $queue_id
 * @property int      $song_id
 * @property int|null $position
 */
final class MixRow extends Model
{
    protected $table = 'pq_mix_rows';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = ['song_id' => 'integer', 'position' => 'integer'];
}
