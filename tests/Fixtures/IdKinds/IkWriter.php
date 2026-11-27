<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property mixed  $id
 * @property string $name
 */
class IkWriter extends Model
{
    use IkKeyed;

    public $timestamps = false;

    protected $table = 'ik_writers';

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<Model, $this>
     */
    public function books(): HasMany
    {
        return $this->hasMany(IdKinds::model('book'), 'writer_id');
    }

    /**
     * @return HasOne<Model, $this>
     */
    public function featured(): HasOne
    {
        return $this->hasOne(IdKinds::model('book'), 'featured_by');
    }

    /**
     * The writers this one seats, through seats whose `owner_id` is this writer.
     *
     * @return BelongsToMany<Model, $this, IkWriterSeat>
     */
    public function peers(): BelongsToMany
    {
        return $this->belongsToMany(IdKinds::model('writer'), 'ik_writer_seats', 'owner_id', 'peer_id')->using(IkWriterSeat::class)->withPivot('rank');
    }

    /**
     * The writers seating this one, through the same seats read from their `peer_id` side.
     *
     * @return BelongsToMany<Model, $this, IkWriterSeat>
     */
    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(IdKinds::model('writer'), 'ik_writer_seats', 'peer_id', 'owner_id')->using(IkWriterSeat::class)->withPivot('rank');
    }
}
