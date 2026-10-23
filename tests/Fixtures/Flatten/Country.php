<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Flatten;

use Illuminate\Database\Eloquent\Model;

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
}
