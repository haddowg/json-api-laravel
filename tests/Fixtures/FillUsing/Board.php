<?php

declare(strict_types=1);

namespace haddowg\JsonApiLaravel\Tests\Fixtures\FillUsing;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 */
final class Board extends Model
{
    public $timestamps = false;

    protected $table = 'fu_boards';

    /**
     * @var list<string>
     */
    protected $guarded = [];
}
