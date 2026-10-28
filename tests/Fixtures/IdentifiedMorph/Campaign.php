<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\IdentifiedMorph;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $target_type
 * @property int|null    $target_id
 */
final class Campaign extends Model
{
    protected $table = 'im_campaigns';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
