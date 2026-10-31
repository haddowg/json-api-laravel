<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelationshipEntityConstraints;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Company extends Model
{
    protected $table = 'rec_companies';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
