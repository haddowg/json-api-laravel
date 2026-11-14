<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\PivotKeys;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class PkCampaign extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];

    protected $table = 'pk_campaigns';
}
