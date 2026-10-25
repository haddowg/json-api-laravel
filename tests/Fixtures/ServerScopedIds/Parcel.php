<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ServerScopedIds;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $label
 */
final class Parcel extends Model
{
    protected $table = 'ssi_parcels';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
