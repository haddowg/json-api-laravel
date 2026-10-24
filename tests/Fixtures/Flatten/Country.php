<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Flatten;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int    $id
 * @property string $name
 */
final class Country extends Model
{
    protected $table = 'fl_countries';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    /**
     * @return HasMany<Author, $this>
     */
    public function authors(): HasMany
    {
        return $this->hasMany(Author::class, 'country_id');
    }
}
