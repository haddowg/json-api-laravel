<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdKinds;

use Illuminate\Database\Eloquent\Model;
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
}
