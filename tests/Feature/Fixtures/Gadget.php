<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Feature\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The related model of {@see Widget}'s `belongsToMany` — its keys (10, 20) are deliberately
 * distinct from the pivot row ids (500, 501), so a pivot-`id` clobber renders an observably
 * wrong JSON:API id.
 *
 * @property int $id
 */
final class Gadget extends Model
{
    protected $table = 'bt_gadgets';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
