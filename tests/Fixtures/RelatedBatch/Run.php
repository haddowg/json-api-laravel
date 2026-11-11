<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\RelatedBatch;

use Illuminate\Database\Eloquent\Model;

/**
 * A run with no relation methods: its `progress` and `advertisers` are served by their own
 * types' providers.
 *
 * @property int    $id
 * @property string $name
 */
final class Run extends Model
{
    protected $table = 'xb_runs';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
