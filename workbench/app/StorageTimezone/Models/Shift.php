<?php

declare(strict_types=1);

namespace Workbench\App\StorageTimezone\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A shift whose `starts_at` holds local time in the app timezone, as Eloquent stores
 * every datetime cast, and whose `day` is a calendar date.
 *
 * @property ?\DateTimeImmutable $starts_at
 * @property ?\DateTimeImmutable $day
 */
final class Shift extends Model
{
    protected $table = 'tz_shifts';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'immutable_datetime',
        'day' => 'immutable_date',
    ];
}
