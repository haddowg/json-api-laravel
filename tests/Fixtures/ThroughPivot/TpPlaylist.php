<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughPivot;

use Illuminate\Database\Eloquent\Model;

final class TpPlaylist extends Model
{
    protected $table = 'tp_playlists';

    public $timestamps = false;

    protected $guarded = [];
}
