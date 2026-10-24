<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\WriteOnlyRelations;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $label
 */
final class Sticker extends Model
{
    protected $table = 'wo_stickers';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
