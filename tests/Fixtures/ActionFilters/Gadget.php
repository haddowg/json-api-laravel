<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\ActionFilters;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 * @property string $colour
 * @property int    $weight
 * @property string $size
 * @property bool   $archived
 */
#[ScopedBy(HideArchived::class)]
final class Gadget extends Model
{
    protected $table = 'af_gadgets';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
