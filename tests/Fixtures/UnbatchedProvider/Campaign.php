<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\UnbatchedProvider;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $title
 * @property int    $advertiser_id
 */
final class Campaign extends Model
{
    protected $table = 'ub_campaigns';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
