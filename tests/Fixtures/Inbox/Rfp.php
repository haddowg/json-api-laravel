<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\Inbox;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */

final class Rfp extends Model
{
    protected $table = 'inb_rfps';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
