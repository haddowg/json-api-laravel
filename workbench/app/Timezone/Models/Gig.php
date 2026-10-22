<?php

declare(strict_types=1);

namespace Workbench\App\Timezone\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int                $id
 * @property string             $title
 * @property \DateTimeImmutable $starts_at
 * @property \DateTimeImmutable $day
 * @property \DateTimeImmutable $doors_open
 * @property int                $venue_id
 */
final class Gig extends Model
{
    protected $table = 'tz_gigs';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'starts_at' => StoredInParis::class,
        'day' => StoredInParis::class . ':date',
        'doors_open' => StoredInParis::class . ':time',
    ];

    /**
     * @return BelongsTo<Venue, $this>
     */
    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class, 'venue_id');
    }

    /**
     * @return BelongsToMany<Performer, $this, Booking>
     */
    public function performers(): BelongsToMany
    {
        return $this->belongsToMany(Performer::class, 'tz_bookings', 'gig_id', 'performer_id')
            ->using(Booking::class)
            ->withPivot('booked_at');
    }
}
