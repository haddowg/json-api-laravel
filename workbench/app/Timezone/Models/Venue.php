<?php

declare(strict_types=1);

namespace Workbench\App\Timezone\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int                $id
 * @property string             $name
 * @property \DateTimeImmutable $opened_at
 */
final class Venue extends Model
{
    protected $table = 'tz_venues';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'opened_at' => StoredInParis::class,
    ];
}
