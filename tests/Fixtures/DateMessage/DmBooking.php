<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\DateMessage;

use Illuminate\Database\Eloquent\Model;

/**
 * A booking on Eloquent.
 *
 * @property int $id
 */
final class DmBooking extends Model
{
    public $timestamps = false;

    protected $table = 'dm_bookings';

    protected $guarded = [];

    protected $casts = [
        'startsAt' => 'immutable_datetime',
        'endsAt' => 'immutable_datetime',
        'day' => 'immutable_date',
        'dueOn' => 'immutable_date',
        'window' => 'array',
        'marks' => 'array',
    ];
}
