<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ThroughAccess;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[ScopedBy(OwnParcelsScope::class)]
final class Parcel extends Model
{
    protected $table = 'ta_parcels';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<Crate, $this>
     */
    public function crate(): BelongsTo
    {
        return $this->belongsTo(Crate::class, 'crate_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'ta_parcel_tag', 'parcel_id', 'tag_id');
    }
}
