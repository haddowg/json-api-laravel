<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Person extends Model
{
    protected $table = 'im_people';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
