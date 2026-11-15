<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsingLinkage;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int         $id
 * @property string      $title
 * @property string|null $label_id
 */
final class Sticky extends Model
{
    public $timestamps = false;

    protected $table = 'fl_stickies';

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
