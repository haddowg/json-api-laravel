<?php

declare(strict_types=1);

namespace Workbench\App\Timezone\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The gig ↔ performer join, cast so `booked_at` reaches the pivot meta as a date rather
 * than the stored string.
 */
final class Booking extends Pivot
{
    protected $table = 'tz_bookings';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'booked_at' => StoredInParis::class,
    ];
}
