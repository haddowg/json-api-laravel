<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifierMeta;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Curator extends Model
{
    protected $table = 'idm_curators';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
